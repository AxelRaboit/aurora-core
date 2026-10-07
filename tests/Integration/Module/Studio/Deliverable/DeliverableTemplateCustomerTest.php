<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\Deliverable;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\Deliverable\Entity\Deliverable;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableCategory;
use Aurora\Module\Studio\Deliverable\Entity\DeliverableLink;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableFormatEnum;
use Aurora\Module\Studio\Deliverable\Enum\DeliverableScopeEnum;
use Aurora\Module\Studio\Deliverable\Repository\DeliverableRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_map;
use function basename;
use function bin2hex;
use function json_decode;
use function random_bytes;
use function sprintf;

/**
 * What a Studio deliverable carries beyond a space deliverable: the
 * "template" flag, the customer it is written for, and its format.
 *
 * What would break silently: a space deliverable that would become a
 * template, a copy in a client space that would stay one or keep the Studio
 * customer, a template the reader cannot read copied anyway, or a customer's
 * name shown to someone without the right to see customers.
 */
final class DeliverableTemplateCustomerTest extends IntegrationTestCase
{
    private const array TEAM = ['studio.deliverables.view', 'studio.deliverables.create', 'studio.deliverables.edit', 'studio.deliverables.delete', 'studio.deliverables.share'];

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    /** @var list<int> */
    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        foreach ([DeliverableLink::class, Deliverable::class, DeliverableCategory::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType = 'Deliverable'", AuditLog::class))->execute();

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        self::getContainer()->get(SettingRepository::class)->set(ModuleParameterEnum::StudioCustomers->value, '1');
        self::getContainer()->get(ModuleAccessChecker::class)->reset();

        parent::tearDown();
    }

    /** A page by default, in the list as in the editor; a slideshow is created on request, an unknown value is not. */
    public function testADeliverableIsAPageByDefaultAndSlidesOnRequest(): void
    {
        $id = $this->create('Proposition', DeliverableScopeEnum::Personal);
        self::assertSame(DeliverableFormatEnum::Page, $this->find($id)->getFormat());
        self::assertSame('page', $this->rowOf($id)['format']);
        self::assertFalse($this->rowOf($id)['template']);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Diaporama', 'format' => 'slides']);
        self::assertResponseIsSuccessful();
        self::assertSame(DeliverableFormatEnum::Slides, $this->find((int) basename((string) $this->json()['editPath']))->getFormat());

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Inconnu', 'format' => 'poster']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('suite.studio.deliverables.errors.format_invalid', $this->json()['errors']['format'] ?? null);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Page explicite', 'format' => 'page']);
        self::assertResponseIsSuccessful();
    }

    /** The "template" flag holds in Studio, reads on the card and in the editor, and does not hold in a space. */
    public function testOnlyAStudioDeliverableIsATemplate(): void
    {
        $id = $this->create('Modèle d\'audit', DeliverableScopeEnum::Shared);
        $saved = $this->update($id, ['template' => true]);

        self::assertTrue($this->find($id)->isTemplate());
        self::assertTrue($this->rowOf($id)['template']);
        self::assertTrue($saved['deliverable']['template']);
        self::assertSame('page', $saved['deliverable']['format']);

        // A save that does not name the flag does not untick it.
        $this->update($id, ['summary' => 'Repris pour chaque client'], withFlags: false);
        self::assertTrue($this->find($id)->isTemplate());

        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/create', $space->getId()), ['title' => 'Audit du client']);
        self::assertResponseIsSuccessful();
        $spaceDeliverable = (int) basename((string) $this->json()['editPath']);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/update', $space->getId(), $spaceDeliverable), [
            ...$this->payload($spaceDeliverable),
            'template' => true,
            'customerId' => $space->getCustomer()->getId(),
        ]);
        self::assertResponseIsSuccessful();

        $inSpace = $this->find($spaceDeliverable);
        self::assertFalse($inSpace->isTemplate(), 'a space deliverable is written for its client, never a template');
        self::assertNull($inSpace->getCustomer(), 'the space names the client');
    }

    /** What is drawn from a template is not one: neither the copy in a client space, nor the copy in Studio. */
    public function testCopiesOfATemplateAreNotTemplates(): void
    {
        $customer = $this->givenCustomer('Prospect Martin');
        $id = $this->create('Modèle de proposition', DeliverableScopeEnum::Shared);
        $this->update($id, ['template' => true, 'customerId' => $customer->getId()]);

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/duplicate', $id), []);
        self::assertResponseIsSuccessful();
        $duplicate = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertFalse($duplicate->isTemplate());
        self::assertSame($customer->getId(), $duplicate->getCustomer()?->getId(), 'a duplicate still speaks to the same client');

        $space = $this->givenSpace();
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/copy-to-space', $id), ['spaceId' => $space->getId()]);
        self::assertResponseIsSuccessful();
        $inSpace = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertFalse($inSpace->isTemplate());
        self::assertNull($inSpace->getCustomer(), 'a copy in a space takes the space\'s client, not the Studio one');
        self::assertSame(DeliverableFormatEnum::Page, $inSpace->getFormat());

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/deliverables/%d/copy-to-studio', $space->getId(), $inSpace->getId()), []);
        self::assertResponseIsSuccessful();
        $back = $this->find((int) basename((string) $this->json()['editPath']));
        self::assertFalse($back->isTemplate());
        self::assertNull($back->getCustomer());

        self::assertTrue($this->find($id)->isTemplate(), 'the template itself stays one');
    }

    /** Starting from a template takes its body, its header image and its category, under the new title. */
    public function testANewDeliverableStartsFromATemplate(): void
    {
        $category = $this->createCategory('Audits');
        $customer = $this->givenCustomer('Prospect Roux');
        $template = $this->create('Modèle d\'audit', DeliverableScopeEnum::Shared);
        $this->update($template, [
            'template' => true,
            'summary' => 'Le gabarit de l\'équipe',
            'categoryId' => $category,
            'customerId' => $customer->getId(),
            'readingHeader' => ['preparedFor' => '[Client]', 'showDate' => false, 'showLogo' => true],
        ]);
        $source = $this->find($template);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Audit de Roux', 'scope' => 'personal', 'fromTemplateId' => $template]);
        self::assertResponseIsSuccessful();
        $created = $this->find((int) basename((string) $this->json()['editPath']));

        self::assertSame('Audit de Roux', $created->getTitle());
        self::assertSame('Le gabarit de l\'équipe', $created->getSummary());
        self::assertSame($source->getGridLayout(), $created->getGridLayout());
        self::assertSame($source->getAppearance(), $created->getAppearance());
        self::assertSame('[Client]', $created->getReadingHeader()['preparedFor'] ?? null);
        self::assertSame($category, $created->getCategory()?->getId(), 'the category comes with the template');
        self::assertSame(DeliverableScopeEnum::Personal, $created->getScope(), 'the scope is the form\'s');
        self::assertSame($this->admin->getId(), $created->getOwner()?->getId());
        self::assertFalse($created->isTemplate(), 'one starts from a template to write to somebody');
        self::assertNull($created->getCustomer(), 'the template\'s client is not the new one\'s');

        // A category named by the dialog wins, even "none".
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Audit sans rangement', 'fromTemplateId' => $template, 'categoryId' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->find((int) basename((string) $this->json()['editPath']))->getCategory());

        $created = array_map(static fn (array $log): string => (string) ($log['from'] ?? ''), $this->auditData('deliverable.created'));
        self::assertContains((string) $template, $created, 'the journal says which template it came from');
    }

    /** A deliverable that is not a template, or someone else's personal template, is not copied: it starts from a blank page. */
    public function testOnlyAReadableTemplateIsCopied(): void
    {
        $notATemplate = $this->create('Brouillon', DeliverableScopeEnum::Shared);
        $this->update($notATemplate, ['summary' => 'Ne doit pas passer']);

        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Depuis un brouillon', 'fromTemplateId' => $notATemplate]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->find((int) basename((string) $this->json()['editPath']))->getSummary());

        $mine = $this->create('Mon modèle perso', DeliverableScopeEnum::Personal);
        $this->update($mine, ['template' => true, 'summary' => 'À moi seul']);

        $teammate = $this->accountWith(self::TEAM);
        $this->client->loginUser($teammate, 'admin');
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => 'Chez le collègue', 'fromTemplateId' => $mine]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->find((int) basename((string) $this->json()['editPath']))->getSummary(), 'a personal template is its author\'s alone');
    }

    /** A Studio deliverable's customer is named in the settings, reads on the card, and the repository finds their deliverables. */
    public function testAStudioDeliverableNamesItsCustomer(): void
    {
        $customer = $this->givenCustomer('Prospect Fabre');
        $id = $this->create('Proposition à Fabre', DeliverableScopeEnum::Shared);
        $saved = $this->update($id, ['customerId' => $customer->getId()]);

        self::assertSame($customer->getId(), $this->find($id)->getCustomer()?->getId());
        self::assertSame($customer->getId(), $saved['deliverable']['customerId']);
        self::assertSame(['id' => $customer->getId(), 'legalName' => 'Prospect Fabre'], $this->rowOf($id)['customer']);

        $other = $this->create('Autre proposition', DeliverableScopeEnum::Shared);
        $this->update($other, ['customerId' => $customer->getId()]);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/delete', $other), []);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $customer = $this->entityManager->find(Customer::class, $customer->getId());
        self::assertInstanceOf(Customer::class, $customer);
        $found = self::getContainer()->get(DeliverableRepository::class)->findLiveStandaloneForCustomer($customer);
        self::assertSame([$id], array_map(static fn (Deliverable $deliverable): ?int => $deliverable->getId(), $found), 'live Studio deliverables only');

        // Deleting the customer record does not delete what was written for them.
        $this->entityManager->createQuery(sprintf('DELETE FROM %s c WHERE c.id = :id', Customer::class))->setParameter('id', $customer->getId())->execute();
        self::assertNull($this->find($id)->getCustomer());
        self::assertSame('Proposition à Fabre', $this->find($id)->getTitle());
    }

    /** Without the right to see customers, or without the module, no name on the card, no picker, no writing. */
    public function testTheCustomerIsForWhoMaySeeCustomers(): void
    {
        $customer = $this->givenCustomer('Prospect discret');
        $other = $this->givenCustomer('Autre prospect');
        $id = $this->create('Proposition discrète', DeliverableScopeEnum::Shared);
        $this->update($id, ['customerId' => $customer->getId()]);

        $teammate = $this->accountWith(self::TEAM);
        $this->client->loginUser($teammate, 'admin');
        self::assertNull($this->rowOf($id)['customer']);

        $this->update($id, ['customerId' => $other->getId()]);
        self::assertSame($customer->getId(), $this->find($id)->getCustomer()?->getId(), 'a customer id from somebody who may not pick one is ignored');

        $this->client->request('GET', sprintf('/suite/studio/deliverables/%d', $id));
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Autre prospect', (string) $this->client->getResponse()->getContent());

        $this->client->loginUser($this->admin, 'admin');
        self::assertNotNull($this->rowOf($id)['customer']);

        self::getContainer()->get(SettingRepository::class)->set(ModuleParameterEnum::StudioCustomers->value, '0');
        self::getContainer()->get(ModuleAccessChecker::class)->reset();
        self::assertNull($this->rowOf($id)['customer'], 'customers switched off are named nowhere');
    }

    /** The log states the format and the "template" flag of each action. */
    public function testTheAuditLogCarriesTheFormatAndTheTemplateFlag(): void
    {
        $id = $this->create('Journalisé', DeliverableScopeEnum::Shared);
        $this->update($id, ['template' => true]);
        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/delete', $id), []);
        self::assertResponseIsSuccessful();

        $created = $this->auditData('deliverable.created')[0] ?? [];
        self::assertSame('page', $created['format'] ?? null);
        self::assertFalse($created['template'] ?? null);

        $trashed = $this->auditData('deliverable.trashed')[0] ?? [];
        self::assertSame('page', $trashed['format'] ?? null);
        self::assertTrue($trashed['template'] ?? null);
    }

    /** @return list<array<string, mixed>> */
    private function auditData(string $action): array
    {
        $this->entityManager->clear();

        return array_map(
            static fn (AuditLog $log): array => $log->getData() ?? [],
            $this->entityManager->getRepository(AuditLog::class)->findBy(['action' => $action, 'entityType' => 'Deliverable']),
        );
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    private function createCategory(string $name): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/categories/create', ['name' => $name]);
        self::assertResponseIsSuccessful();

        return (int) $this->json()['categoryId'];
    }

    /** @return array<string, mixed> */
    private function rowOf(int $id): array
    {
        $this->client->request('GET', '/suite/studio/deliverables/lists');
        self::assertResponseIsSuccessful();
        $lists = $this->json();

        foreach ([...$lists['personal'], ...$lists['shared']] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        self::fail(sprintf("Le livrable %d n'est dans aucun rayon.", $id));
    }

    private function create(string $title, DeliverableScopeEnum $scope): int
    {
        $this->client->jsonRequest('POST', '/suite/studio/deliverables/create', ['title' => $title, 'scope' => $scope->value]);
        self::assertResponseIsSuccessful();

        return (int) basename((string) $this->json()['editPath']);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed> the response
     */
    private function update(int $id, array $changes, bool $withFlags = true): array
    {
        $payload = $this->payload($id);
        if (!$withFlags) {
            unset($payload['template'], $payload['customerId']);
        }

        $this->client->jsonRequest('POST', sprintf('/suite/studio/deliverables/%d/update', $id), [...$payload, ...$changes]);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /** @return array<string, mixed> what the editor sends, without the date: nothing is compared */
    private function payload(int $id): array
    {
        $entity = $this->find($id);

        return [
            'title' => $entity->getTitle(),
            'summary' => $entity->getSummary(),
            'locale' => $entity->getLocale(),
            'gridLayout' => $entity->getGridLayout(),
            'gridContent' => $entity->getGridContent(),
            'appearance' => $entity->getAppearance(),
            'readingHeader' => $entity->getReadingHeader(),
            'visibleToClient' => $entity->isVisibleToClient(),
            'categoryId' => $entity->getCategory()?->getId(),
            'thumbnailId' => $entity->getThumbnail()?->getId(),
            'template' => $entity->isTemplate(),
            'customerId' => $entity->getCustomer()?->getId(),
        ];
    }

    private function find(int $id): Deliverable
    {
        $this->entityManager->clear();
        $deliverable = $this->entityManager->find(Deliverable::class, $id);
        self::assertInstanceOf(Deliverable::class, $deliverable);

        return $deliverable;
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user
            ->setEmail('modeles-'.bin2hex(random_bytes(5)).'@aurora.app')
            ->setName('Équipier '.bin2hex(random_bytes(2)))
            ->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])
            ->setPassword('irrelevant')
            ->setPrivileges($privileges);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function givenCustomer(string $legalName): Customer
    {
        $customer = new Customer();
        $customer->setLegalName($legalName)->setContractualEmail(bin2hex(random_bytes(4)).'@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $customer;
    }

    private function givenSpace(): CustomerSpace
    {
        $customer = $this->givenCustomer('Client modèles');

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', [
            'name' => 'Espace modèles '.(new DateTimeImmutable())->format('His'),
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->getRepository(CustomerSpace::class)->find($this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }
}
