<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Contract;

use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateInput;
use Aurora\Module\Studio\Contract\Dto\ContractTemplateVersionInput;
use Aurora\Module\Studio\Contract\Entity\Contract;
use Aurora\Module\Studio\Contract\Entity\ContractTemplate;
use Aurora\Module\Studio\Contract\Entity\ContractTemplateInterface;
use Aurora\Module\Studio\Contract\Enum\ContractTemplateKindEnum;
use Aurora\Module\Studio\Contract\Manager\ContractTemplateManager;
use Aurora\Module\Studio\Contract\Service\ContractSeal;
use Aurora\Module\Studio\Contract\Signature\Entity\ContractSignature;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function json_decode;
use function json_encode;
use function sprintf;

/**
 * A trame adapted for one client, without a new trame.
 *
 * The text is copied into the contract, edited there, and sealed with it.
 * What these tests hold: the adapted text is the one sealed; the trame and
 * every other contract are untouched; the adapted text obeys the trame's
 * rules; a blank it adds is one more field to fill; changing the trame or
 * the language drops it; a sealed contract refuses it; a duplicate keeps it.
 */
final class ContractWordingTest extends IntegrationTestCase
{
    private const string CLAUSE = 'Clause particulière : livraison le samedi.';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();
        $admin = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        $this->client->loginUser($admin, 'admin');

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery('DELETE FROM '.ContractSignature::class)->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Contract::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', ContractTemplate::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s', Customer::class))->execute();

        parent::tearDown();
    }

    public function testTheAdaptedTextIsTheOneSealedAndTheTrameIsUntouched(): void
    {
        $template = $this->publishedTemplate('Contrat mensuel');
        $customer = $this->customer('Boulangerie Durand', '73282932000074');
        $adapted = $this->createContract($customer, $template);
        $other = $this->createContract($customer, $template);

        $saved = $this->post(sprintf('/backend/studio/contracts/%d/wording/body/save', $adapted), $this->wording([
            ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est de {{contract.amount}} pour {{customer.legal_name}}.']],
            ['type' => 'paragraph', 'data' => ['text' => self::CLAUSE]],
        ]));
        self::assertSame(200, $saved['status']);
        self::assertTrue($saved['body']['contract']['body']['isAdapted']);

        self::assertSame(200, $this->post(sprintf('/backend/studio/contracts/%d/freeze', $adapted))['status']);
        self::assertSame(200, $this->post(sprintf('/backend/studio/contracts/%d/freeze', $other))['status']);

        $this->entityManager->clear();
        $sealed = $this->entityManager->find(Contract::class, $adapted);
        $untouched = $this->entityManager->find(Contract::class, $other);

        self::assertStringContainsString(self::CLAUSE, (string) $sealed->getRenderedHtml());
        self::assertStringContainsString('Boulangerie Durand', (string) $sealed->getRenderedHtml());
        self::assertTrue($sealed->getContentSnapshot()['parts'][0]['adapted']);
        self::assertTrue(static::getContainer()->get(ContractSeal::class)->verify($sealed));

        self::assertStringNotContainsString(self::CLAUSE, (string) $untouched->getRenderedHtml());
        self::assertFalse($untouched->getContentSnapshot()['parts'][0]['adapted']);

        $trame = $this->entityManager->find(ContractTemplate::class, $template->getId());
        $wording = (string) json_encode($trame?->getLatestPublishedVersion()?->getTranslation('fr')?->getContent());
        self::assertStringNotContainsString('samedi', $wording);
    }

    public function testTheAdaptedTextIsHeldToTheTramesRule(): void
    {
        $id = $this->createContract($this->customer('Boulangerie Durand', '73282932000074'), $this->publishedTemplate('Contrat mensuel'));
        $path = sprintf('/backend/studio/contracts/%d/wording/body/save', $id);

        $unknown = $this->post($path, $this->wording([['type' => 'paragraph', 'data' => ['text' => 'Pour {{client.siret}}.']]]));
        self::assertSame(422, $unknown['status']);
        self::assertStringContainsString('client.siret', (string) $unknown['body']['errors']['content']);

        $image = $this->post($path, $this->wording([['type' => 'image', 'data' => ['file' => ['url' => '/x.png']]]]));
        self::assertSame(422, $image['status']);
        self::assertArrayHasKey('content', $image['body']['errors']);

        self::assertSame(422, $this->post($path, $this->wording([]))['status']);
        self::assertSame(422, $this->post($path, ['title' => '  ', 'content' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x']]]]])['status']);

        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(Contract::class, $id)->isAdapted());
    }

    public function testABlankAddedInTheAdaptationMustBeFilledBeforeTheSeal(): void
    {
        $customer = $this->customer('Boulangerie Durand', '73282932000074');
        $template = $this->publishedTemplate('Contrat mensuel');
        $id = $this->createContract($customer, $template);

        self::assertSame(200, $this->post(sprintf('/backend/studio/contracts/%d/wording/body/save', $id), $this->wording([
            ['type' => 'paragraph', 'data' => ['text' => 'Une remise de {{contract.custom.remise}} est consentie.']],
        ]))['status']);

        self::assertSame(422, $this->post(sprintf('/backend/studio/contracts/%d/freeze', $id))['status']);

        $updated = $this->post(sprintf('/backend/studio/contracts/%d/update', $id), [
            ...$this->contractPayload($customer, $template),
            'customFields' => ['remise' => '10 %'],
        ]);
        self::assertSame(200, $updated['status']);
        // Same trame, same language: the adaptation survives an edit.
        self::assertTrue($updated['body']['contract']['body']['isAdapted']);

        self::assertSame(200, $this->post(sprintf('/backend/studio/contracts/%d/freeze', $id))['status']);
        $this->entityManager->clear();
        self::assertStringContainsString('Une remise de 10 % est consentie.', (string) $this->entityManager->find(Contract::class, $id)->getRenderedHtml());
    }

    public function testAnotherTrameOrAnotherLanguageDropsTheAdaptation(): void
    {
        $customer = $this->customer('Boulangerie Durand', '73282932000074');
        $template = $this->publishedTemplate('Contrat mensuel');
        $id = $this->createContract($customer, $template);
        $this->post(sprintf('/backend/studio/contracts/%d/wording/body/save', $id), $this->wording([['type' => 'paragraph', 'data' => ['text' => self::CLAUSE]]]));

        $other = $this->publishedTemplate('Contrat ponctuel');
        $moved = $this->post(sprintf('/backend/studio/contracts/%d/update', $id), $this->contractPayload($customer, $other));

        self::assertSame(200, $moved['status']);
        self::assertFalse($moved['body']['contract']['body']['isAdapted']);

        $this->entityManager->clear();
        $contract = $this->entityManager->find(Contract::class, $id);
        $contract->adaptWording(ContractTemplateKindEnum::Body, 'Titre', [['type' => 'paragraph', 'data' => ['text' => 'x']]], new DateTimeImmutable());
        $contract->setLocale('en');
        self::assertFalse($contract->isAdapted());
    }

    public function testResetGoesBackToTheTramesText(): void
    {
        $id = $this->createContract($this->customer('Boulangerie Durand', '73282932000074'), $this->publishedTemplate('Contrat mensuel'));
        $this->post(sprintf('/backend/studio/contracts/%d/wording/body/save', $id), $this->wording([['type' => 'paragraph', 'data' => ['text' => self::CLAUSE]]]));

        $reset = $this->post(sprintf('/backend/studio/contracts/%d/wording/body/reset', $id));

        self::assertSame(200, $reset['status']);
        self::assertFalse($reset['body']['contract']['body']['isAdapted']);
        self::assertStringNotContainsString(self::CLAUSE, (string) $reset['body']['contract']['renderedHtml']);
    }

    public function testASealedContractRefusesAnAdaptation(): void
    {
        $id = $this->createContract($this->customer('Boulangerie Durand', '73282932000074'), $this->publishedTemplate('Contrat mensuel'));
        $this->post(sprintf('/backend/studio/contracts/%d/freeze', $id));

        $refused = $this->post(sprintf('/backend/studio/contracts/%d/wording/body/save', $id), $this->wording([['type' => 'paragraph', 'data' => ['text' => self::CLAUSE]]]));
        self::assertSame(422, $refused['status']);
        self::assertSame(422, $this->post(sprintf('/backend/studio/contracts/%d/wording/body/reset', $id))['status']);

        // Still readable: the page shows what was sealed.
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/wording/body', $id));
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        // A part the contract does not have is not a page.
        $this->client->request('GET', sprintf('/backend/studio/contracts/%d/wording/annex', $id));
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /** A contract cancelled to correct one detail keeps every clause negotiated. */
    public function testADuplicateKeepsTheAdaptedText(): void
    {
        $id = $this->createContract($this->customer('Boulangerie Durand', '73282932000074'), $this->publishedTemplate('Contrat mensuel'));
        $this->post(sprintf('/backend/studio/contracts/%d/wording/body/save', $id), $this->wording([['type' => 'paragraph', 'data' => ['text' => self::CLAUSE]]]));

        $copy = $this->post(sprintf('/backend/studio/contracts/%d/duplicate', $id));
        self::assertSame(200, $copy['status']);

        $this->entityManager->clear();
        $contracts = $this->entityManager->getRepository(Contract::class)->findBy([], ['id' => 'DESC']);
        self::assertTrue($contracts[0]->isAdapted(ContractTemplateKindEnum::Body));
        self::assertNotSame($id, $contracts[0]->getId());
    }

    /** @param list<array<string, mixed>> $blocks */
    private function wording(array $blocks): array
    {
        return ['title' => 'CONTRAT DE PRESTATION', 'content' => ['blocks' => $blocks]];
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function post(string $path, array $payload = []): array
    {
        $this->client->jsonRequest('POST', $path, $payload);

        return [
            'status' => $this->client->getResponse()->getStatusCode(),
            'body' => json_decode((string) $this->client->getResponse()->getContent(), true) ?? [],
        ];
    }

    private function createContract(Customer $customer, ContractTemplateInterface $template): int
    {
        $created = $this->post('/backend/studio/contracts/create', $this->contractPayload($customer, $template));
        self::assertSame(200, $created['status']);

        return (int) $created['body']['contract']['id'];
    }

    /** @return array<string, mixed> */
    private function contractPayload(Customer $customer, ContractTemplateInterface $template): array
    {
        return [
            'customerId' => $customer->getId(),
            'bodyTemplateId' => $template->getId(),
            'locale' => 'fr',
            'amount' => '850',
            'amountCurrency' => 'EUR',
            'effectiveDate' => '2026-11-01',
        ];
    }

    private function publishedTemplate(string $name): ContractTemplateInterface
    {
        $templates = static::getContainer()->get(ContractTemplateManager::class);
        $template = $templates->create(new ContractTemplateInput($name, ContractTemplateKindEnum::Body));
        $version = $template->getDraft();

        $templates->updateDraft($version, new ContractTemplateVersionInput([
            'fr' => [
                'title' => 'CONTRAT DE PRESTATION',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'Le forfait est de {{contract.amount}} pour {{customer.legal_name}}.']],
                ]],
            ],
            'en' => [
                'title' => 'SERVICE AGREEMENT',
                'content' => ['blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'The fee is {{contract.amount}} for {{customer.legal_name}}.']],
                ]],
            ],
        ], 'fr'));
        $templates->publish($version);

        return $template;
    }

    private function customer(string $name, string $siret): Customer
    {
        $customer = new Customer();
        $customer->setLegalName($name)->setSiret($siret)->setContractualEmail('contact@durand.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }
}
