<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio\CustomerSpace;

use Aurora\Core\Trash\TrashSummary;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Dev\Audit\Entity\AuditLog;
use Aurora\Module\Notes\Space\Entity\NoteSpace;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Module\Studio\Customer\Entity\Customer;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpace;
use Aurora\Module\Studio\CustomerSpace\Entity\CustomerSpaceMember;
use Aurora\Module\Studio\CustomerSpace\Message\PurgeTrashedSpacesMessage;
use Aurora\Module\Studio\CustomerSpace\MessageHandler\PurgeTrashedSpacesHandler;
use Aurora\Module\Studio\CustomerSpace\Repository\CustomerSpaceRepository;
use Aurora\Module\Studio\CustomerSpace\Trash\CustomerSpacesTrashSource;
use Aurora\Module\Studio\Dashboard\StudioStatsProvider;
use Aurora\Module\Studio\Search\StudioSuiteSearchProvider;
use Aurora\Module\Studio\SpaceAccess\Entity\SpaceAccessLink;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Module\Studio\SpaceNote\Service\SpaceNoteSpaceProvider;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function array_map;
use function json_decode;
use function parse_url;
use function sprintf;
use function str_contains;

use const PHP_URL_PATH;

/**
 * Client spaces in the common trash.
 *
 * A space holds months of a client's work, and deleting one by mistake took
 * everything with it. Deleting now puts it in the trash: it leaves the lists,
 * the search, the counts and the calendar, its screens and its client page
 * answer like an unknown address, and a restore brings everything back as it
 * was. Only « delete for good » and the scheduled purge destroy it, and until
 * then it still stands in the way of deleting its customer.
 */
final class CustomerSpaceTrashTest extends IntegrationTestCase
{
    private const string SOURCE = 'studio.space_content';

    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    private CustomerSpaceRepository $spaceRepository;

    /** @var list<int> */
    private array $users = [];

    /** @var list<int> */
    private array $noteSpaces = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->spaceRepository = self::getContainer()->get(CustomerSpaceRepository::class);

        $admin = self::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->clear();

        foreach ([SpaceAccessLink::class, SpaceContentItem::class, SpaceContentColumn::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        foreach ($this->noteSpaces as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s s WHERE s.id = :id', NoteSpace::class))->setParameter('id', $id)->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s a WHERE a.entityType IN ('CustomerSpace', 'SpaceContentItem', 'Customer', 'NoteSpace')", AuditLog::class))->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s e WHERE e.sourceType = :source', PlanningEvent::class))->setParameter('source', self::SOURCE)->execute();
        $this->entityManager->createQuery(sprintf('DELETE FROM %s p WHERE p.sourceType = :source', Planning::class))->setParameter('source', self::SOURCE)->execute();

        foreach ($this->users as $id) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s u WHERE u.id = :id', User::class))->setParameter('id', $id)->execute();
        }

        parent::tearDown();
    }

    public function testATrashedSpaceIsHiddenEverywhereAndComesBackWhole(): void
    {
        $space = $this->givenSpace('Boulangerie Corbeille');
        $id = (int) $space->getId();
        $itemId = $this->givenItem($id, 'Croissant du mardi', '+3 days 10:00');
        $guestPath = $this->issueLink($id);

        self::assertNotNull($this->events()->findBySource(self::SOURCE, $itemId), 'the date is on the calendar');
        $search = self::getContainer()->get(StudioSuiteSearchProvider::class);
        self::assertSame([$id], array_column($search->search('Corbeille')['spaces'] ?? [], 'id'), 'found while alive');
        self::assertSame([$itemId], array_column($search->search('Croissant')['space_contents'] ?? [], 'id'));
        self::assertSame('opened', $this->guestOpens($guestPath));
        $this->client->loginUser($this->admin, 'admin');

        $this->post(sprintf('/suite/studio/spaces/%d/delete', $id));
        self::assertResponseIsSuccessful();
        self::assertNotContains($id, array_column($this->json()['spaces'], 'id'), 'gone from the list it answers with');

        $this->entityManager->clear();
        $trashed = $this->spaceRepository->findTrashed($id);
        self::assertNotNull($trashed);
        self::assertTrue($trashed->isTrashed());

        // Its screens answer like an unknown space.
        $this->client->request('GET', sprintf('/workspace/%d', $id));
        self::assertResponseStatusCodeSame(404);
        $this->post(sprintf('/workspace/%d/content/%d/update', $id, $itemId), ['title' => 'x']);
        self::assertResponseStatusCodeSame(404);
        $this->post(sprintf('/suite/studio/spaces/%d/update', $id), ['name' => 'Renommé']);
        self::assertResponseStatusCodeSame(404);

        // Off the list, the search, the dashboard and the shared calendar.
        self::assertNotContains($id, array_map(static fn ($row): ?int => $row->getId(), $this->spaceRepository->findAllOrdered()));
        $found = self::getContainer()->get(StudioSuiteSearchProvider::class)->search('Corbeille');
        self::assertSame([], $found['spaces'] ?? []);
        self::assertSame([], $found['space_contents'] ?? []);
        self::assertSame([], self::getContainer()->get(StudioSuiteSearchProvider::class)->search('Croissant')['space_contents'] ?? []);
        $stats = self::getContainer()->get(StudioStatsProvider::class)->getStats()['studio'];
        self::assertNotContains($id, array_column($stats['attention'], 'id'));
        self::assertNull($this->events()->findBySource(self::SOURCE, $itemId), 'its dates left the calendar');

        // Its client page and its access link answer like an unknown token.
        self::assertSame('unavailable', $this->guestOpens($guestPath));

        $this->client->loginUser($this->admin, 'admin');
        $this->post(sprintf('/suite/studio/spaces/%d/restore', $id));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $restored = $this->spaceRepository->find($id);
        self::assertNotNull($restored);
        self::assertFalse($restored->isTrashed());
        self::assertContains($id, array_map(static fn ($row): ?int => $row->getId(), $this->spaceRepository->findAllOrdered()));
        self::assertNotNull($this->events()->findBySource(self::SOURCE, $itemId), 'its dates are back on the calendar');
        self::assertSame('opened', $this->guestOpens($guestPath), 'the same link answers again');

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', sprintf('/workspace/%d', $id));
        self::assertResponseIsSuccessful();
    }

    public function testDeletingForGoodTakesEverythingWithIt(): void
    {
        $space = $this->givenSpace('Espace à détruire');
        $id = (int) $space->getId();
        $this->givenItem($id, 'Emporté', null);

        // Not from the trash's buttons while it is alive.
        $this->post(sprintf('/suite/studio/spaces/%d/force-delete', $id));
        self::assertResponseStatusCodeSame(404);

        $this->post(sprintf('/suite/studio/spaces/%d/delete', $id));
        $this->post(sprintf('/suite/studio/spaces/%d/force-delete', $id));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        self::assertNull($this->spaceRepository->find($id));
        self::assertSame([], $this->entityManager->getRepository(SpaceContentItem::class)->findBy(['space' => $id]));
    }

    public function testThePurgeDestroysOnlyWhatHasBeenInTheTrashLongEnough(): void
    {
        $settings = self::getContainer()->get(SettingRepository::class);
        $settings->set(ApplicationParameterEnum::TrashAutoPurgeDays->value, '30');

        $old = (int) $this->givenSpace('Jeté il y a longtemps')->getId();
        $recent = (int) $this->givenSpace('Jeté hier')->getId();
        $alive = (int) $this->givenSpace('Toujours là')->getId();
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $old));
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $recent));
        $this->trashedAt($old, new DateTimeImmutable('-45 days'));
        $this->trashedAt($recent, new DateTimeImmutable('-1 day'));

        self::getContainer()->get(PurgeTrashedSpacesHandler::class)(new PurgeTrashedSpacesMessage());

        $this->entityManager->clear();
        self::assertNull($this->spaceRepository->find($old));
        self::assertNotNull($this->spaceRepository->find($recent));
        self::assertNotNull($this->spaceRepository->find($alive));
    }

    /**
     * A space in the trash still names its customer, and still holds the
     * work a restore would bring back: the customer cannot be deleted until
     * the space is destroyed for good.
     */
    public function testATrashedSpaceStillBlocksDeletingItsCustomer(): void
    {
        $space = $this->givenSpace('Espace du client');
        $id = (int) $space->getId();
        $customerId = (int) $space->getCustomer()->getId();

        $this->post(sprintf('/suite/studio/spaces/%d/delete', $id));
        $this->post(sprintf('/suite/studio/customers/%d/delete', $customerId));
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('corbeille', (string) ($this->json()['errors']['customer'] ?? ''));

        $this->post(sprintf('/suite/studio/spaces/%d/force-delete', $id));
        $this->post(sprintf('/suite/studio/customers/%d/delete', $customerId));
        self::assertResponseIsSuccessful();
    }

    /**
     * Its note space follows it to the notes' trash, still managed by it: the
     * notes screen cannot bring it back alone, and it comes back with the space.
     */
    public function testItsNoteSpaceFollowsItToTheTrashAndBack(): void
    {
        $space = $this->givenSpace('Espace avec notes');
        $id = (int) $space->getId();
        $noteSpaceId = (int) self::getContainer()->get(SpaceNoteSpaceProvider::class)->resolve($space)->getId();
        $this->noteSpaces[] = $noteSpaceId;

        $this->post(sprintf('/suite/studio/spaces/%d/delete', $id));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $noteSpace = $this->entityManager->find(NoteSpace::class, $noteSpaceId);
        self::assertNotNull($noteSpace);
        self::assertNotNull($noteSpace->getDeletedAt());
        self::assertTrue($noteSpace->isManaged(), 'still managed by its client space');

        $this->post(sprintf('/suite/notes/spaces/%d/restore', $noteSpaceId));
        self::assertResponseStatusCodeSame(409);

        $this->post(sprintf('/suite/studio/spaces/%d/restore', $id));
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $noteSpace = $this->entityManager->find(NoteSpace::class, $noteSpaceId);
        self::assertNotNull($noteSpace);
        self::assertNull($noteSpace->getDeletedAt());
        self::assertTrue($noteSpace->isManaged());
    }

    public function testTheTrashScreenListsTheSpacesOfTheReadersTeamOnly(): void
    {
        $teammate = $this->accountWith(['studio.spaces.view', 'studio.spaces.edit', 'studio.spaces.delete']);
        $ours = $this->givenSpace('Le nôtre', [['userId' => $teammate->getId(), 'role' => 'lead']]);
        $theirs = $this->givenSpace('Pas le nôtre');
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $ours->getId()));
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $theirs->getId()));

        $source = self::getContainer()->get(CustomerSpacesTrashSource::class);
        $summary = $source->getSummary(10);
        self::assertInstanceOf(TrashSummary::class, $summary);
        self::assertSame(2, $summary->count);
        self::assertSame('suite_studio_spaces_restore', $summary->restoreRoute);
        self::assertSame('studio.spaces.delete', $summary->actionPrivilege);

        $this->client->loginUser($teammate, 'admin');
        $mine = $source->getSummary(10);
        self::assertSame(1, $mine->count);
        self::assertSame(['Le nôtre'], array_column($mine->items, 'label'));

        // The other team's space is not theirs to restore, nor to destroy.
        $this->post(sprintf('/suite/studio/spaces/%d/restore', $theirs->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->post('/suite/studio/spaces/empty-trash');
        self::assertSame(1, $this->json()['deleted']);

        $this->entityManager->clear();
        self::assertNull($this->spaceRepository->find($ours->getId()));
        self::assertNotNull($this->spaceRepository->find($theirs->getId()));

        $this->client->loginUser($this->admin, 'admin');
        $this->client->request('GET', '/suite/trash/list');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('studio_spaces', (string) $this->client->getResponse()->getContent());
    }

    public function testTrashingRestoringAndDestroyingAreInTheAuditLog(): void
    {
        $id = (int) $this->givenSpace('Tracé')->getId();
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $id));
        $this->post(sprintf('/suite/studio/spaces/%d/restore', $id));
        $this->post(sprintf('/suite/studio/spaces/%d/delete', $id));
        $this->post(sprintf('/suite/studio/spaces/%d/force-delete', $id));

        $this->entityManager->clear();
        $actions = array_map(
            static fn (AuditLog $log): string => $log->getAction(),
            $this->entityManager->getRepository(AuditLog::class)->findBy(['entityType' => 'CustomerSpace', 'entityId' => $id]),
        );

        self::assertContains('customer_space.trashed', $actions);
        self::assertContains('customer_space.restored', $actions);
        self::assertContains('customer_space.deleted', $actions);
    }

    /** @param list<array{userId: int|null, role: string}> $members */
    private function givenSpace(string $name, array $members = []): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client de '.$name)->setContractualEmail('trash@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->post('/suite/studio/spaces/create', [
            'name' => $name,
            'customerId' => $customer->getId(),
            'timezone' => 'Europe/Paris',
            'members' => $members,
        ]);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->find(CustomerSpace::class, $this->json()['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** A card on the Review step, shown to the client, dated or not; its id. */
    private function givenItem(int $spaceId, string $title, ?string $when): int
    {
        $space = $this->entityManager->find(CustomerSpace::class, $spaceId);
        self::assertInstanceOf(CustomerSpace::class, $space);
        $review = self::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($space)[2];

        $this->post(sprintf('/workspace/%d/content/create', $spaceId), [
            'title' => $title,
            'columnId' => $review->getId(),
            'scheduledAt' => null === $when ? null : new DateTimeImmutable($when)->format('Y-m-d\TH:i'),
        ]);
        self::assertResponseIsSuccessful();

        $items = $this->json()['items'];

        return (int) $items[array_search($title, array_column($items, 'title'), true)]['id'];
    }

    /** An access link for the space; the path the client opens. */
    private function issueLink(int $spaceId): string
    {
        $this->post(sprintf('/workspace/%d/access/issue', $spaceId), [
            'recipientEmail' => 'client@example.test',
            'label' => 'Le client',
        ]);
        self::assertResponseIsSuccessful();

        return (string) parse_url($this->json()['url'], PHP_URL_PATH);
    }

    /** « unavailable » when the refusal page is served, « opened » otherwise. */
    private function guestOpens(string $path): string
    {
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();

        return str_contains((string) $this->client->getResponse()->getContent(), 'plus valide') ? 'unavailable' : 'opened';
    }

    private function trashedAt(int $id, DateTimeImmutable $at): void
    {
        $this->entityManager->createQuery(sprintf('UPDATE %s s SET s.deletedAt = :at WHERE s.id = :id', CustomerSpace::class))
            ->setParameter('at', $at)
            ->setParameter('id', $id)
            ->execute();
    }

    /** @param list<string> $privileges */
    private function accountWith(array $privileges): User
    {
        $user = new User();
        $user->setEmail(sprintf('corbeille-espaces-%d@example.test', count($this->users)))->setName('Équipier')->setType(UserTypeEnum::Suite)
            ->setRoles([UserRoleEnum::User->value])->setPassword('x')->setPrivileges($privileges);
        $this->entityManager->persist($user);
        $this->entityManager->flush();
        $this->users[] = (int) $user->getId();

        return $user;
    }

    private function events(): PlanningEventRepository
    {
        return self::getContainer()->get(PlanningEventRepository::class);
    }

    /** @param array<string, mixed> $payload */
    private function post(string $path, array $payload = []): void
    {
        $this->client->jsonRequest('POST', $path, $payload);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }
}
