<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Core\Storage\StorageManager;
use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Entity\CoreUserInterface;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Access\Repository\ContractAccessLinkRepository;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractInterface;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractStatusEnum;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Exception\ContractPdfAlreadyGeneratedException;
use Aurora\Module\Studio\Contract\Integrity\ContractIntegrityChecker;
use Aurora\Module\Studio\Contract\Manager\ContractManagerInterface;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Message\VerifyContractsMessage;
use Aurora\Module\Studio\Contract\MessageHandler\VerifyContractsHandler;
use Aurora\Module\Studio\Contract\Orphan\ContractReferencedKeysProvider;
use Aurora\Module\Studio\Contract\Preview\ContractTemplatePreviewer;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\Serializer\ContractSerializer;
use Aurora\Module\Studio\Contract\Service\ContractPdfGenerator;
use Aurora\Module\Studio\Contract\Service\ContractSignedDocument;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureChallengeRepository;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;
use function end;
use function implode;
use function iterator_to_array;
use function json_decode;
use function mb_substr;
use function preg_match;
use function sprintf;

/**
 * The PDF, and the one rule about it: it is written once.
 *
 * A document regenerated later would be whatever today's renderer, fonts and
 * template produce, and the second copy would differ from the one the parties
 * were sent without anybody noticing. So the interesting tests here are the
 * refusals and the stability of the hash, not the fact that a file appears.
 */
final class ContractPdfTest extends IntegrationTestCase
{
    private const string PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC';

    private KernelBrowser $client;

    private ?CoreUserInterface $admin = null;

    private ContractTemplateManager $contractTemplateManager;

    private ContractAccessLinkRepository $accessLinkRepository;

    private ContractSignatureChallengeRepository $challengeRepository;

    private ContractSignatureRepository $contractSignatureRepository;

    private ContractRepository $contractRepository;

    private ContractPdfGenerator $pdfGenerator;

    private StorageManager $storageManager;

    private EntityManagerInterface $entityManager;

    /** @var array<string, mixed> what the countersignature answered, for the screen */
    private array $countersigned = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $this->admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        $this->login();

        // The signing endpoints are rate limited by IP, and every test in this
        // class comes from the same one. The limiter state lives in a shared
        // cache pool that outlives a test, so a class exercising the flow
        // several times trips a limit that is doing exactly its job. Cleared
        // here rather than raised in config: the limit is deliberate, and this
        // class is not what tests it.
        $container->get('cache.rate_limiter')->clear();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->accessLinkRepository = $container->get(ContractAccessLinkRepository::class);
        $this->challengeRepository = $container->get(ContractSignatureChallengeRepository::class);
        $this->contractSignatureRepository = $container->get(ContractSignatureRepository::class);
        $this->contractRepository = $container->get(ContractRepository::class);
        $this->pdfGenerator = $container->get(ContractPdfGenerator::class);
        $this->storageManager = $container->get(StorageManager::class);

        $this->contractTemplateManager = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
            $container->get(ContractTemplatePreviewer::class),
        );
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.ContractSignature::class)->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testTheCountersignatureWritesThePdfAndHashesIt(): void
    {
        $contract = $this->concludedContract();

        self::assertTrue($contract->hasPdf());
        self::assertNotNull($contract->getPdfGeneratedAt());
        self::assertMatchesRegularExpression('#^contracts/\d{4}/[A-Z]+-\d{4}-\d{4}\.pdf$#', (string) $contract->getPdfPath());

        self::assertTrue($this->pdfGenerator->exists($contract), 'The countersignature has to leave a file behind.');

        // The file's own hash, distinct from the document's: two artefacts, two
        // hashes, so a PDF swapped in storage is detectable even though the
        // contract still verifies against its own seal.
        self::assertSame(hash('sha256', $this->readPdf($contract)), $contract->getPdfHash());
        self::assertNotSame($contract->getContentHash(), $contract->getPdfHash());

        // A real PDF, not an HTML page with the wrong extension.
        self::assertStringStartsWith('%PDF-', $this->readPdf($contract));
    }

    /**
     * The rule, enforced.
     *
     * Refused loudly rather than answered with the existing path: a caller
     * asking for a second one has misunderstood the state it is in, and
     * returning the old file quietly would hide that.
     */
    public function testASecondPdfIsRefused(): void
    {
        $contract = $this->concludedContract();

        $this->expectException(ContractPdfAlreadyGeneratedException::class);

        $this->pdfGenerator->generate($contract, $this->contractSignatureRepository->findForContract($contract));
    }

    public function testTheEntityRefusesASecondFileToo(): void
    {
        $contract = $this->concludedContract();

        // The guard sits on the entity as well as the generator, so no code
        // path can attach a second file by going around the service.
        $this->expectException(ContractPdfAlreadyGeneratedException::class);

        $contract->attachPdf('contracts/2026/other.pdf', str_repeat('a', 64), new DateTimeImmutable());
    }

    public function testThePdfCarriesTheEvidenceAndBothSignatures(): void
    {
        $contract = $this->concludedContract();
        $bytes = $this->readPdf($contract);

        // dompdf compresses its streams, so the text is not greppable in the
        // output. What is checkable without a PDF parser is that both
        // signatures reached the template and the file has two pages' worth of
        // content rather than an empty shell.
        self::assertGreaterThan(3000, mb_strlen($bytes), 'A contract with two signatures and a seal block is not a 3 KB file.');
        self::assertCount(2, $this->contractSignatureRepository->findForContract($contract));
    }

    /**
     * « Fait à …, le … », written in from the customer's signature.
     *
     * The two tokens stay in the sealed HTML, which is what the hash covers,
     * and are filled only when the document is shown. Nothing filled them: the
     * signed PDF printed the braces.
     */
    public function testTheSignersCityAndDateAreWrittenIntoTheDocument(): void
    {
        $contract = $this->concludedContract();
        $html = static::getContainer()->get(ContractSignedDocument::class)->html($contract);

        self::assertStringContainsString('Fait à Lyon, le 08/09/2026.', $html);
        self::assertStringNotContainsString('{{', $html);

        // The sealed text itself is untouched, so the seal still holds.
        self::assertStringContainsString('{{contract.signature_city}}', (string) $contract->getRenderedHtml());
    }

    public function testBeforeAnySignatureTheCityAndDateAreADottedBlank(): void
    {
        $url = $this->sentContractUrl();
        $contract = $this->accessLinkRepository->findAll()[0]->getContract();

        $html = static::getContainer()->get(ContractSignedDocument::class)->html($contract);
        self::assertStringContainsString(sprintf('Fait à %s, le %s.', ContractSignedDocument::BLANK, ContractSignedDocument::BLANK), $html);

        // And the page the client signs on shows the same blank, not the token.
        $guest = $this->asGuest();
        $guest->request('GET', $url);
        self::assertSame(200, $guest->getResponse()->getStatusCode());
        self::assertStringNotContainsString('{{contract.signature_city}}', (string) $guest->getResponse()->getContent());
    }

    /**
     * The screen replaces its state with this answer, so it has to carry the
     * seal: without it the page said « le sceau ne correspond plus » the moment
     * the contract was concluded.
     */
    public function testTheCountersignatureAnswersWithTheSealIntact(): void
    {
        $this->concludedContract();

        self::assertTrue($this->countersigned['contract']['seal']['verified'] ?? false);
        self::assertArrayHasKey('amendments', $this->countersigned['contract']);
        self::assertArrayHasKey('renderedHtml', $this->countersigned['contract']);
    }

    /**
     * The signature keeps the address its code went to. The PDF used to print
     * the customer's current address, which may have changed since.
     */
    public function testTheSignatureKeepsTheAddressTheCodeWentTo(): void
    {
        $contract = $this->concludedContract();
        $customer = $this->contractSignatureRepository->findForContract($contract)[0];

        self::assertSame('contact@durand.test', $customer->getChallengeSentTo());
    }

    /**
     * A PDF that cannot be written concludes nothing.
     *
     * The status used to be saved before the file was generated: a failure
     * then left a contract concluded with no PDF, and a second
     * countersignature is refused, so nothing could ever make one.
     */
    public function testAFailedPdfLeavesTheContractWaitingForTheCountersignature(): void
    {
        $contractId = $this->signedByCustomer();
        $contract = $this->contractRepository->find($contractId);

        // A file already where the PDF goes: the generator refuses to write
        // over a signed document, which is the failure this simulates.
        $this->storageManager->active()->write($this->pdfGenerator->relativePathFor($contract), 'in the way');

        $this->client->catchExceptions(true);
        $this->countersign($contractId);
        self::assertSame(500, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();
        $reloaded = $this->contractRepository->find($contractId);

        self::assertSame(ContractStatusEnum::SignedByCustomer, $reloaded->getStatus());
        self::assertFalse($reloaded->hasPdf());
        self::assertCount(1, $this->contractSignatureRepository->findForContract($reloaded));

        $this->storageManager->active()->delete($this->pdfGenerator->relativePathFor($reloaded));
    }

    /**
     * Once the retention has run out, a signed contract can go too.
     *
     * The signatures' key refuses a cascade, and the delete did not remove
     * them first: a signed contract answered a 500 however old it was, while
     * the tests only ever deleted one nobody had signed.
     */
    public function testASignedContractIsDeletableOnceTheRetentionHasElapsed(): void
    {
        $contract = $this->concludedContract();
        $id = $contract->getId();

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET frozen_at = :frozenAt WHERE id = :id',
            ['frozenAt' => new DateTimeImmutable('-30 years')->format('Y-m-d H:i:s'), 'id' => $id],
        );

        // Through the container of the last request: the client rebooted the
        // kernel, and the manager has to delete an entity its own manager holds.
        $container = static::getContainer();
        $repository = $container->get(ContractRepository::class);
        $container->get(EntityManagerInterface::class)->clear();
        $container->get(ContractManagerInterface::class)->delete($repository->find($id));

        $container->get(EntityManagerInterface::class)->clear();
        self::assertNull($repository->find($id));
    }

    /** Nobody signs a document that no longer matches its seal. */
    public function testAnAlteredDocumentCannotBeSigned(): void
    {
        $contractId = $this->signedByCustomer();

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contracts SET rendered_html = rendered_html || '<p>Clause ajoutée.</p>' WHERE id = :id",
            ['id' => $contractId],
        );

        $this->countersign($contractId);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('sceau', (string) $this->client->getResponse()->getContent());
    }

    public function testThePdfIsServedThroughItsOwnGatedRouteAndNotTheCatchAll(): void
    {
        $contract = $this->concludedContract();

        $this->login();
        $this->client->request('GET', sprintf('/suite/studio/contracts/%d/pdf', $contract->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertStringContainsString(
            (string) $contract->getReference(),
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    /**
     * The export of a concluded contract is the signed file, byte for byte.
     *
     * This is the rule the whole export hangs on. One action on the list, three
     * answers, and for a contract that has been signed the only honest answer
     * is the bytes that were hashed - a fresh render would be today's templates
     * wearing the reference of a signed document.
     */
    public function testTheExportOfAConcludedContractIsTheStoredFile(): void
    {
        $contract = $this->concludedContract();

        $this->login();
        $this->client->request('GET', sprintf('/suite/studio/contracts/%d/export', $contract->getId()));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $disposition = (string) $this->client->getResponse()->headers->get('Content-Disposition');
        // No working-copy suffix: there is nothing provisional about this one.
        self::assertStringContainsString(sprintf('%s.pdf', (string) $contract->getReference()), $disposition);
        self::assertStringNotContainsString('projet', $disposition);

        self::assertSame($this->readPdf($contract), $this->streamed());
    }

    /**
     * And the generator refuses to build a look-alike for it.
     *
     * The guard sits under the route rather than only in it: a second caller
     * asking for a provisional copy of a signed contract is asking for a file
     * that would differ from the one the parties hold, and be indistinguishable
     * from it once saved.
     */
    public function testAProvisionalRenderIsRefusedOnceTheSignedFileExists(): void
    {
        $contract = $this->concludedContract();

        $this->expectException(ContractPdfAlreadyGeneratedException::class);

        $this->pdfGenerator->renderProvisional($contract, '<p>anything</p>', []);
    }

    /**
     * A guest cannot read it.
     *
     * The route lives under `/suite` and carries the contract permission, so
     * the session is what stands between somebody with the URL and a signed
     * document.
     */
    public function testAGuestCannotFetchThePdf(): void
    {
        $contract = $this->concludedContract();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', sprintf('/suite/studio/contracts/%d/pdf', $contract->getId()));

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAContractWithNoPdfAnswers404(): void
    {
        $url = $this->sentContractUrl();
        $contractId = $this->accessLinkRepository->findAll()[0]->getContract()->getId();

        $this->login();
        $this->client->request('GET', sprintf('/suite/studio/contracts/%d/pdf', $contractId));

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The signed copy travels with the mail.
     *
     * This is the mail the customer keeps, and a link they would have to be
     * logged in to follow is not a copy.
     */
    public function testTheConcludedMailCarriesThePdf(): void
    {
        $contract = $this->concludedContract();

        $attached = false;

        foreach ($this->mailerMessages() as $message) {
            foreach ($message->getAttachments() as $attachment) {
                if (str_contains((string) $attachment->getFilename(), (string) $contract->getReference())) {
                    $attached = true;
                }
            }
        }

        self::assertTrue($attached, 'The concluded mail has to carry the signed PDF.');
    }

    /**
     * The morning check: silent while everything matches, and the one
     * contract that moved named, whichever of its three hashes moved.
     */
    public function testTheIntegrityCheckIsCleanOnAConcludedContract(): void
    {
        $contract = $this->concludedContract();
        $report = static::getContainer()->get(ContractIntegrityChecker::class)->check();

        self::assertTrue($report->isClean(), implode("\n", $report->altered));
        self::assertSame(1, $report->checked);

        // The file the check reads is one the orphan sweep keeps.
        $keys = iterator_to_array(static::getContainer()->get(ContractReferencedKeysProvider::class)->referencedKeys(), false);
        self::assertContains($contract->getPdfPath(), $keys);
    }

    public function testTheIntegrityCheckNamesAReplacedPdf(): void
    {
        $contract = $this->concludedContract();
        $this->storageManager->active()->write((string) $contract->getPdfPath(), 'a different file');

        $altered = static::getContainer()->get(ContractIntegrityChecker::class)->check()->altered;

        self::assertCount(1, $altered);
        self::assertStringContainsString((string) $contract->getReference(), $altered[0]);
        self::assertStringContainsString('PDF no longer matches', $altered[0]);
    }

    public function testTheIntegrityCheckNamesAMissingPdf(): void
    {
        $contract = $this->concludedContract();
        $this->storageManager->active()->delete((string) $contract->getPdfPath());

        $altered = static::getContainer()->get(ContractIntegrityChecker::class)->check()->altered;

        self::assertCount(1, $altered);
        self::assertStringContainsString('missing', $altered[0]);
    }

    /** A document re-sealed under a signature verifies, and is still caught. */
    public function testTheIntegrityCheckNamesASignatureGivenOnAnotherText(): void
    {
        $contractId = $this->signedByCustomer();

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contract_signatures SET signed_content_hash = 'sha256:autre' WHERE contract_id = :id",
            ['id' => $contractId],
        );

        $altered = static::getContainer()->get(ContractIntegrityChecker::class)->check()->altered;

        self::assertCount(1, $altered);
        self::assertStringContainsString('customer signature', $altered[0]);
    }

    /**
     * The scheduled run mails the administrator when a document moved, and
     * the command exits 1 on the same finding.
     */
    public function testAnAlteredDocumentIsReportedByTheScheduleAndTheCommand(): void
    {
        $contractId = $this->signedByCustomer();

        $tester = new CommandTester((new Application(static::$kernel))->find('aurora:contracts:verify'));
        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $this->entityManager->getConnection()->executeStatement(
            "UPDATE core_contracts SET rendered_html = rendered_html || '<p>Clause ajoutée.</p>' WHERE id = :id",
            ['id' => $contractId],
        );
        // The first run left the contract in the identity map, as it was.
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('no longer matches its seal', $tester->getDisplay());

        $before = count($this->mailerMessages());
        static::getContainer()->get(VerifyContractsHandler::class)(new VerifyContractsMessage());
        $mails = $this->mailerMessages();

        self::assertCount($before + 1, $mails);
        self::assertStringContainsString('scellé', (string) end($mails)->getSubject());
    }

    /**
     * The customer's signature counts as activity. It used to count for
     * nothing: the active link is gone once they have signed, so a contract
     * waiting for the countersignature read as last touched the day it was
     * sent.
     */
    public function testTheLastActivityOfASignedContractIsItsSignature(): void
    {
        $contractId = $this->signedByCustomer();
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement("UPDATE core_contracts SET created_at = NOW() - INTERVAL '10 days', frozen_at = NOW() - INTERVAL '10 days', last_reminder_at = NULL WHERE id = :id", ['id' => $contractId]);
        $connection->executeStatement("UPDATE core_contract_access_links SET sent_at = NOW() - INTERVAL '10 days', last_used_at = NOW() - INTERVAL '10 days', revoked_at = CASE WHEN revoked_at IS NULL THEN NULL ELSE NOW() - INTERVAL '10 days' END WHERE contract_id = :id", ['id' => $contractId]);
        $connection->executeStatement("UPDATE core_contract_signatures SET signed_at = NOW() - INTERVAL '2 days' WHERE contract_id = :id", ['id' => $contractId]);
        $this->entityManager->clear();

        $row = static::getContainer()->get(ContractSerializer::class)->serializeMany([$this->contractRepository->find($contractId)])[0];

        self::assertSame(new DateTimeImmutable('-2 days')->format('Y-m-d'), mb_substr((string) $row['lastActivityAt'], 0, 10));
    }

    /**
     * What the streamed response actually wrote out.
     *
     * Off the BrowserKit response rather than the Symfony one: a
     * `StreamedResponse` has no content to give back, and the test client
     * already buffered what the callback echoed.
     */
    private function streamed(): string
    {
        return (string) $this->client->getInternalResponse()->getContent();
    }

    /**
     * The stored bytes, whichever backend holds them.
     *
     * Reads through the generator rather than off the disk: these tests used
     * to assume the file was one `is_file()` away, which stopped being true
     * the moment a contract could live on a remote backend.
     */
    private function readPdf(ContractInterface $contract): string
    {
        $bytes = '';
        foreach ($this->pdfGenerator->readStream($contract) as $chunk) {
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** Signed by the customer, not yet countersigned. */
    private function signedByCustomer(): int
    {
        $url = $this->sentContractUrl();
        $contractId = $this->accessLinkRepository->findAll()[0]->getContract()->getId();

        $guest = $this->asGuest();
        $guest->jsonRequest('POST', $url.'/code');
        $guest->jsonRequest('POST', $url.'/sign', [
            'firstName' => 'Camille',
            'lastName' => 'Durand',
            'email' => 'camille@durand.test',
            'place' => 'Lyon',
            'date' => '2026-09-08',
            'signatureImage' => self::PNG,
            'consent' => true,
            'code' => $this->latestCode(),
        ]);
        self::assertSame(200, $guest->getResponse()->getStatusCode());

        return $contractId;
    }

    private function countersign(int $contractId): void
    {
        $this->login();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/countersign', $contractId), [
            'firstName' => 'Axel',
            'lastName' => 'Raboit',
            'email' => 'axel@example.test',
            'place' => 'Grenoble',
            'date' => '2026-09-09',
            'signatureImage' => self::PNG,
            'consent' => true,
            'code' => 'n/a',
        ]);
        $this->countersigned = json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function concludedContract(): Contract
    {
        $contractId = $this->signedByCustomer();
        $this->countersign($contractId);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();

        return $this->contractRepository->find($contractId);
    }

    private function latestCode(): string
    {
        $this->entityManager->clear();
        $challenge = $this->challengeRepository->findLatestFor($this->accessLinkRepository->findAll()[0]);
        self::assertNotNull($challenge);

        for ($candidate = 0; $candidate <= 999999; ++$candidate) {
            $guess = sprintf('%06d', $candidate);

            if (hash('sha256', $guess) === $challenge->getHashedCode()) {
                return $guess;
            }
        }

        self::fail('The stored hash matches no six-digit code.');
    }

    private function sentContractUrl(): string
    {
        $this->client->jsonRequest('POST', '/suite/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
            'amount' => '850',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/freeze', $id));
        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/send', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        foreach ($this->mailerMessages() as $message) {
            if (1 === preg_match('#(/contracts/[a-f0-9]{32}/[a-f0-9]{64})#', (string) $message->getHtmlBody(), $matches)) {
                return $matches[1];
            }
        }

        self::fail('No mail carried the signing address.');
    }

    /** @return list<Email> */
    private function mailerMessages(): array
    {
        $messages = [];

        foreach ($this->getMailerEvents() as $event) {
            if ($event->isQueued()) {
                continue;
            }

            $message = $event->getMessage();
            self::assertInstanceOf(Email::class, $message);
            $messages[] = $message;
        }

        return $messages;
    }

    private function asGuest(): KernelBrowser
    {
        $this->client->getCookieJar()->clear();

        return $this->client;
    }

    private function login(): void
    {
        self::assertNotNull($this->admin);
        $this->client->loginUser($this->admin, 'admin');
    }

    private function publishedTemplate(): ContractTemplateInterface
    {
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->contractTemplateManager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1 - OBJET', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait mensuel est de {{contract.amount}} pour {{customer.legal_name}}.']],
                    ['type' => 'paragraph', 'data' => ['text' => 'Fait à {{contract.signature_city}}, le {{contract.signature_date}}.']],
                ]],
            ],
        ]));
        $this->contractTemplateManager->publish($version);

        return $template;
    }

    private function customer(): CustomerInterface
    {
        $customer = new Customer();
        $customer
            ->setLegalName('Boulangerie Durand')
            ->setContractualEmail('contact@durand.test');

        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }
}
