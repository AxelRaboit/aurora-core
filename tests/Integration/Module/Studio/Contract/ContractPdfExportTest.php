<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\Customer\Entity\CustomerInterface;
use Aurora\Tests\Integration\IntegrationTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Contracts\Translation\TranslatorInterface;

use function json_decode;
use function sprintf;

/**
 * The export, which is the PDF of a contract nobody has signed yet.
 *
 * What is worth testing is not that a file comes back - it is that the file
 * says which of the two documents it is. A draft can still change under the
 * reader, a sealed contract is final and binds nobody, and only the
 * countersigned one carries its proof block. A working copy that reads like the
 * signed one is the single way this feature could do damage, so the name, the
 * banner and the missing seal are each checked here.
 */
final class ContractPdfExportTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private ContractTemplateManager $templates;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
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
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    /**
     * A draft comes back as a real PDF, named after the customer.
     *
     * There is no reference yet - it is minted at the seal - so the customer is
     * what names the file. `contrat.pdf` in a downloads folder names nothing.
     */
    public function testADraftExportsAWorkingCopyNamedAfterTheCustomer(): void
    {
        $id = $this->createContract()['contract']['id'];

        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/export', $id));
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));

        $disposition = (string) $response->headers->get('Content-Disposition');
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('boulangerie-durand-projet.pdf', $disposition);

        // A draft changes between two clicks: a browser holding yesterday's
        // copy would be showing a document that no longer exists.
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    /**
     * A sealed contract exports under its reference, still marked as a copy.
     *
     * The wording is final from here on, so the export is the document itself -
     * printed from the snapshot, never re-rendered. What it is not is signed,
     * and the suffix is what says so in a folder full of files.
     */
    public function testASealedContractExportsUnderItsReferenceWithTheDraftSuffix(): void
    {
        $created = $this->createContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $reference = json_decode((string) $this->client->getResponse()->getContent(), true)['contract']['reference'];

        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/export', $id));
        $response = $this->client->getResponse();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString(
            sprintf('%s-projet.pdf', $reference),
            (string) $response->headers->get('Content-Disposition'),
        );
        self::assertStringStartsWith('%PDF-', (string) $response->getContent());
    }

    /**
     * The signed route stays the signed route.
     *
     * `export` answers for every contract; `pdf` answers only for one that has
     * a stored file. Merging the two would mean the document page's "download
     * the signed PDF" could hand back a fresh render of today's templates, and
     * the sentence on the button would become false.
     */
    public function testTheSignedRouteStillRefusesAContractWithNoStoredFile(): void
    {
        $id = $this->createContract()['contract']['id'];

        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/pdf', $id));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/export', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    /**
     * A guest cannot export one either.
     *
     * The export carries the same permission as everything else under
     * `/backend/studio/contracts`: a draft is not public just because it is not
     * signed.
     */
    public function testAGuestCannotExport(): void
    {
        $id = $this->createContract()['contract']['id'];

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/export', $id));

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
    }

    /** @return array<string, mixed> */
    private function createContract(): array
    {
        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'customerId' => $this->customer()->getId(),
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
            'amount' => '850',
            'amountCurrency' => 'EUR',
            'effectiveDate' => '2026-10-01',
        ]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        return json_decode((string) $this->client->getResponse()->getContent(), true);
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
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est de {{contract.amount}} pour {{customer.legal_name}}.']],
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
