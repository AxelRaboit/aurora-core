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
use Aurora\Module\Studio\Contract\Preview\ContractTemplatePreviewer;
use Aurora\Module\Studio\Contract\Repository\ContractRepository;
use Aurora\Module\Studio\Contract\Repository\ContractTemplateVersionRepository;
use Aurora\Module\Studio\Contract\View\ContractsViewBuilder;
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

    private ContractTemplateManager $contractTemplateManager;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $container = static::getContainer();

        $admin = $container->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = $container->get(EntityManagerInterface::class);
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
        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/freeze', $id));

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

    /**
     * The journey through the routes the contract's screen calls: seal, send,
     * remind, cancel is refused while the customer holds a link, revoke,
     * cancel, and a draft to correct.
     */
    public function testTheJourneyThroughTheContractsScreenRoutes(): void
    {
        $id = $this->createContract()['contract']['id'];
        $post = function (string $gesture) use ($id): array {
            $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/%s', $id, $gesture));

            return ['status' => $this->client->getResponse()->getStatusCode(), 'body' => json_decode((string) $this->client->getResponse()->getContent(), true)];
        };

        self::assertSame('draft', $this->createContract()['contract']['step']);

        // A reminder before anything went out is refused, with a sentence.
        $post('freeze');
        self::assertSame(422, $post('remind')['status']);

        $sent = $post('send');
        self::assertSame(200, $sent['status']);
        self::assertSame('with_customer', $sent['body']['contract']['step']);
        // The whole document comes back, seal included, for the screen.
        self::assertTrue($sent['body']['contract']['seal']['verified']);

        self::assertSame(200, $post('remind')['status']);

        // Out with the customer: revoke first, then cancel.
        self::assertSame(422, $post('cancel')['status']);
        self::assertSame('revoked', $post('revoke-link')['body']['contract']['status']);

        $cancelled = $post('cancel');
        self::assertSame(200, $cancelled['status']);
        self::assertSame('cancelled', $cancelled['body']['contract']['status']);
        self::assertSame('ended', $cancelled['body']['contract']['step']);

        $copy = $post('duplicate');
        self::assertSame(200, $copy['status']);
        self::assertSame('draft', $copy['body']['contract']['status']);
        self::assertNotSame($id, $copy['body']['contract']['id']);
        self::assertStringContainsString((string) $copy['body']['contract']['id'], (string) $copy['body']['showPath']);
    }

    public function testASealedContractRefusesEveryFurtherWrite(): void
    {
        $created = $this->createContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/freeze', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/update', $id), [
            'customerId' => $created['contract']['customerId'],
            'bodyTemplateId' => $created['contract']['body']['templateId'],
            'amount' => '9999',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $refused = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('status', $refused['errors']);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/delete', $id));
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDocumentPageShowsTheSealAsIntact(): void
    {
        $created = $this->createContract();
        $id = $created['contract']['id'];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/freeze', $id));

        $this->client->request('GET', sprintf('/suite/studio/contracts/%d', $id));

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

        $this->client->jsonRequest('POST', sprintf('/suite/studio/contracts/%d/freeze', $id));

        // What a manual database edit would do.
        $this->entityManager->getConnection()->executeStatement(
            'UPDATE core_contracts SET rendered_html = :html WHERE id = :id',
            ['html' => '<section><h1>CONTRAT</h1><p>Le forfait est de 1 €.</p></section>', 'id' => $id],
        );

        $this->client->request('GET', sprintf('/suite/studio/contracts/%d', $id));

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // The page says so rather than showing the altered document as valid.
        self::assertStringContainsString('&quot;verified&quot;:false', (string) $this->client->getResponse()->getContent());
    }

    public function testATemplateWithNothingPublishedIsRefusedAtCreation(): void
    {
        $customer = $this->customer();
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Trame vierge', ContractTemplateKindEnum::Body));

        $this->client->jsonRequest('POST', '/suite/studio/contracts/create', [
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
        $this->client->jsonRequest('POST', '/suite/studio/contracts/create', [
            'bodyTemplateId' => $this->publishedTemplate()->getId(),
            'locale' => 'fr',
        ]);

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertArrayHasKey('customerId', $payload['errors']);
    }

    /**
     * The list costs the same queries for two contracts or four.
     *
     * Each row looked up its active signing link, its pinned versions, their
     * templates and every version of those templates, one contract at a time.
     */
    public function testTheListDoesNotGrowWithTheContracts(): void
    {
        $this->createContract();
        $this->createContract();
        $withTwo = $this->queriesForTheList();

        $this->createContract();
        $this->createContract();
        $withFour = $this->queriesForTheList();

        self::assertSame($withTwo, $withFour, 'two more contracts, not one more query');
    }

    /**
     * The template picker costs the same queries for two templates or four.
     *
     * Each template loaded all its versions to find the latest published one,
     * then that version's wording to list the blanks it asks for.
     */
    public function testTheTemplatePickerDoesNotGrowWithTheTemplates(): void
    {
        $this->publishedTemplate();
        $this->publishedTemplate();
        $this->queriesForTheForm();
        $withTwo = $this->queriesForTheForm();

        $this->publishedTemplate();
        $this->publishedTemplate();
        $withFour = $this->queriesForTheForm();

        self::assertSame($withTwo, $withFour, 'two more templates, not one more query');
    }

    private function queriesForTheForm(): int
    {
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $holder = $container->get('doctrine.debug_data_holder');
        $holder->reset();

        $view = $container->get(ContractsViewBuilder::class)->indexView();
        self::assertNotEmpty($view['bodies']);

        return count($holder->getData()['default'] ?? []);
    }

    private function queriesForTheList(): int
    {
        $container = static::getContainer();
        $container->get(EntityManagerInterface::class)->clear();
        $holder = $container->get('doctrine.debug_data_holder');
        $holder->reset();

        $payload = $container->get(ContractsViewBuilder::class)->listPayload();
        self::assertNotEmpty($payload['contracts']);

        return count($holder->getData()['default'] ?? []);
    }

    /** @return array<string, mixed> */
    private function createContract(): array
    {
        $this->client->jsonRequest('POST', '/suite/studio/contracts/create', [
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
        $template = $this->contractTemplateManager->create(new ContractTemplateInput('Contrat mensuel', ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $this->contractTemplateManager->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION DE SERVICES',
                'content' => ['blocks' => [
                    ['type' => 'header', 'data' => ['text' => 'ARTICLE 1', 'level' => 2]],
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est de {{contract.amount}} pour {{customer.legal_name}}.']],
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
