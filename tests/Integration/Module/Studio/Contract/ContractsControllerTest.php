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
 * The contract endpoint, which is also what finally gives the manager and the
 * seal an HTTP consumer.
 *
 * Three of these are about the sealing: that it happens over the wire, that a
 * sealed contract refuses every further write with a sentence, and that the
 * document page recomputes the seal rather than trusting a column.
 */
final class ContractsControllerTest extends IntegrationTestCase
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

    public function testAContractIsPreparedThenSealed(): void
    {
        $created = $this->createContract();

        self::assertTrue($created['success']);
        self::assertNull($created['contract']['reference']);
        self::assertTrue($created['contract']['isEditable']);
        // The version published today is pinned at creation, not at freeze.
        self::assertSame(1, $created['contract']['body']['versionNumber']);
        self::assertFalse($created['contract']['body']['isOutdated']);

        $id = $created['contract']['id'];
        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $sealed = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertTrue($sealed['contract']['isFrozen']);
        self::assertNotNull($sealed['contract']['reference']);
        // Sealed, not sent: the link has not gone out yet, and the two are
        // separate acts a day apart.
        self::assertSame('sealed', $sealed['contract']['status']);
        // The page to go to next is handed back: what somebody wants right
        // after sealing is to see what was sealed.
        self::assertStringContainsString((string) $id, (string) $sealed['showPath']);
    }

    public function testASealedContractRefusesEveryFurtherWrite(): void
    {
        $created = $this->createContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/update', $id), [
            'customerId' => $created['contract']['customerId'],
            'bodyTemplateId' => $created['contract']['body']['templateId'],
            'amount' => '9999',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $refused = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('status', $refused['errors']);

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/delete', $id));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDocumentPageShowsTheSealAsIntact(): void
    {
        $created = $this->createContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));

        $this->client->request('GET', sprintf('/backend/studio/contracts/%d', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $body = (string) $this->client->getResponse()->getContent();

        // The seal reaches the page, verified on this request.
        self::assertStringContainsString('&quot;verified&quot;:true', $body);
        self::assertStringContainsString('sha256', $body);
    }

    public function testASealedDocumentReportsItsSealBrokenAfterTampering(): void
    {
        $created = $this->createContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/backend/studio/contracts/%d/freeze', $id));

        // What a manual database edit would do.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET rendered_html = :html WHERE id = :id',
            ['html' => '<section><h1>CONTRAT</h1><p>Le forfait est de 1 €.</p></section>', 'id' => $id],
        );

        $this->client->request('GET', sprintf('/backend/studio/contracts/%d', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // The page says so rather than showing the altered document as valid.
        self::assertStringContainsString('&quot;verified&quot;:false', (string) $this->client->getResponse()->getContent());
    }

    public function testATemplateWithNothingPublishedIsRefusedAtCreation(): void
    {
        $customer = $this->customer();
        $template = $this->templates->create(new ContractTemplateInput('Trame vierge', ContractTemplateKindEnum::Body));

        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'customerId' => $customer->getId(),
            'bodyTemplateId' => $template->getId(),
            'locale' => 'fr',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('bodyTemplateId', $payload['errors']);
        self::assertStringContainsString('Trame vierge', $payload['errors']['bodyTemplateId']);
    }

    public function testAContractWithoutACustomerIsRefused(): void
    {
        $this->client->jsonRequest('POST', '/backend/studio/contracts/create', [
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('customerId', $payload['errors']);
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
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1', 'level' => 2]],
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
