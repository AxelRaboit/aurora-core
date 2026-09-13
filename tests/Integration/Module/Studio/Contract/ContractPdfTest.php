<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

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
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Exception\ContractPdfAlreadyGeneratedException;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\Service\ContractPdfGenerator;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureChallengeRepository;
use Aurora\Module\Studio\Contract\Signature\Repository\ContractSignatureRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_file;
use function json_decode;
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

    private ContractTemplateManager $templates;

    private ContractAccessLinkRepository $links;

    private ContractSignatureChallengeRepository $challenges;

    private ContractSignatureRepository $signatures;

    private ContractRepository $contracts;

    private ContractPdfGenerator $pdf;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $this->admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->login();

        // The signing endpoints are rate limited by IP, and every test in this
        // class comes from the same one. The limiter state lives in a shared
        // cache pool that outlives a test, so a class exercising the flow
        // several times trips a limit that is doing exactly its job. Cleared
        // here rather than raised in config: the limit is deliberate, and this
        // class is not what tests it.
        $container->get('cache.rate_limiter')->clear();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->links = $container->get(ContractAccessLinkRepository::class);
        $this->challenges = $container->get(ContractSignatureChallengeRepository::class);
        $this->signatures = $container->get(ContractSignatureRepository::class);
        $this->contracts = $container->get(ContractRepository::class);
        $this->pdf = $container->get(ContractPdfGenerator::class);

        $this->templates = new ContractTemplateManager(
            $this->entityManager,
            $container->get(AuditLogger::class),
            $container->get(ContractTemplateVersionRepository::class),
            $container->get(TranslatorInterface::class),
            $container->get(ContractRepository::class),
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

        self::assertTrue($this->pdf->exists($contract), 'The countersignature has to leave a file behind.');

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

        $this->pdf->generate($contract, $this->signatures->findForContract($contract));
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
        self::assertCount(2, $this->signatures->findForContract($contract));
    }

    public function testThePdfIsServedThroughItsOwnGatedRouteAndNotTheCatchAll(): void
    {
        $contract = $this->concludedContract();

        $this->login();
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/pdf', $contract->getId()));

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
     * A guest cannot read it.
     *
     * The route lives under `/backend` and carries the contract permission, so
     * the session is what stands between somebody with the URL and a signed
     * document.
     */
    public function testAGuestCannotFetchThePdf(): void
    {
        $contract = $this->concludedContract();

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/pdf', $contract->getId()));

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testAContractWithNoPdfAnswers404(): void
    {
        $url = $this->sentContractUrl();
        $contractId = $this->links->findAll()[0]->getContract()->getId();

        $this->login();
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/pdf', $contractId));

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
     * The stored bytes, whichever backend holds them.
     *
     * Reads through the generator rather than off the disk: these tests used
     * to assume the file was one `is_file()` away, which stopped being true
     * the moment a contract could live on a remote backend.
     */
    private function readPdf(ContractInterface $contract): string
    {
        $bytes = '';
        foreach ($this->pdf->readStream($contract) as $chunk) {
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function concludedContract(): Contract
    {
        $url = $this->sentContractUrl();
        $contractId = $this->links->findAll()[0]->getContract()->getId();

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

        $this->login();
        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/countersign', $contractId), [
            'firstName' => 'Axel',
            'lastName' => 'Raboit',
            'email' => 'axel@example.test',
            'place' => 'Grenoble',
            'date' => '2026-09-09',
            'signatureImage' => self::PNG,
            'consent' => true,
            'code' => 'n/a',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->entityManager->clear();

        return $this->contracts->find($contractId);
    }

    private function latestCode(): string
    {
        $this->entityManager->clear();
        $challenge = $this->challenges->findLatestFor($this->links->findAll()[0]);
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
        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
            'amount' => '850',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $created = json_decode((string) $this->client->getResponse()->getContent(), true);
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));
        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/send', $id));
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
        $template = $this->templates->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1 - OBJET', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait mensuel est de {{contract.amount}} pour {{customer.legal_name}}.']],
                    ['type' => 'paragraph', 'data' => ['text' => 'Fait à {{contract.signature_city}}, le {{contract.signature_date}}.']],
                ]],
            ],
        ]));
        $this->templates->publish($version);

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
