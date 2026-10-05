<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Studio;

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
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceMemberRoleEnum;
use Aurora\Module\Studio\CustomerSpace\Enum\CustomerSpaceStatusEnum;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentColumn;
use Aurora\Module\Studio\SpaceContent\Entity\SpaceContentItem;
use Aurora\Module\Studio\SpaceContent\Manager\SpaceContentItemManager;
use Aurora\Module\Studio\SpaceContent\Repository\SpaceContentColumnRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

use function array_column;
use function json_decode;
use function sprintf;
use function urlencode;

/**
 * Screens that read across spaces, and what each reader gets from them.
 *
 * The rule is the one every Studio screen already asks: a member sees their
 * spaces, somebody who sees everything sees everything - « mine » first when
 * they are a member of some. The editorial calendar, the dashboard and the
 * calendar module all go through it now, and a client's schedule no longer
 * reaches everybody who opens the calendar.
 */
final class StudioCrossSpaceAccessTest extends IntegrationTestCase
{
    private KernelBrowser $client;

    private EntityManagerInterface $entityManager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $admin = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'suite']);
        self::assertInstanceOf(User::class, $admin);
        $this->admin = $admin;
        $this->client->loginUser($admin, 'admin');
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf("DELETE FROM %s e WHERE e.sourceType = 'studio.space_content'", PlanningEvent::class))->execute();
        $this->entityManager->createQuery(sprintf("DELETE FROM %s p WHERE p.sourceType = 'studio.space_content'", Planning::class))->execute();

        foreach ([SpaceContentItem::class, SpaceContentColumn::class, CustomerSpaceMember::class, CustomerSpace::class, Customer::class] as $class) {
            $this->entityManager->createQuery(sprintf('DELETE FROM %s', $class))->execute();
        }

        $this->entityManager->createQuery(sprintf("DELETE FROM %s u WHERE u.email LIKE 'transverse-%%'", User::class))->execute();

        parent::tearDown();
    }

    public function testTheEditorialCalendarShowsAMemberTheirSpacesOnly(): void
    {
        $mine = $this->givenSpace('Le mien', '73282932000074');
        $theirs = $this->givenSpace('Le leur', '39860733100024');
        $this->givenScheduledItem($mine, 'Publication du mien');
        $this->givenScheduledItem($theirs, 'Publication du leur');

        $member = $this->givenTeammate(['studio.spaces.view', 'studio.spaces.edit']);
        $this->givenMembership($mine, $member);

        $this->client->loginUser($member, 'admin');
        self::assertSame(['Publication du mien'], $this->calendarTitles('mine'));
        self::assertSame(['Publication du mien'], $this->calendarTitles('all'), '« all » is not a way round membership');

        $this->client->request('GET', '/suite/studio/calendar');
        self::assertResponseIsSuccessful();
    }

    /** Somebody who sees everything gets their own first, and everything on asking. */
    public function testAnAdministratorChoosesBetweenTheirSpacesAndAll(): void
    {
        $mine = $this->givenSpace('Le mien', '73282932000074');
        $theirs = $this->givenSpace('Le leur', '39860733100024');
        $this->givenScheduledItem($mine, 'Publication du mien');
        $this->givenScheduledItem($theirs, 'Publication du leur');

        // A member of nothing sees everything, « mine » included.
        self::assertSame(['Publication du leur', 'Publication du mien'], $this->calendarTitles('mine'));

        $this->givenMembership($mine, $this->admin);
        self::assertSame(['Publication du mien'], $this->calendarTitles('mine'));
        self::assertSame(['Publication du leur', 'Publication du mien'], $this->calendarTitles('all'));
    }

    /** A panel whose figures the reader may not open is not on their dashboard. */
    public function testTheDashboardLeavesOutAPanelTheReaderMayNotRead(): void
    {
        $reader = $this->givenTeammate(['general.dashboard.view', 'planning.calendars.view']);
        $this->client->loginUser($reader, 'admin');

        $enabled = $this->dashboardProps()['enabledModules'];

        self::assertFalse($enabled['studio'], 'no right to look at spaces');
        self::assertTrue($enabled['planning']);
        self::assertArrayNotHasKey('studio', $this->dashboardProps()['stats']);
    }

    /**
     * A client's publications reach the calendar for the members of that
     * client's space, not for everybody who opens the calendar.
     */
    public function testTheCalendarModuleShowsAClientsScheduleToItsMembersOnly(): void
    {
        $mine = $this->givenSpace('Le mien', '73282932000074');
        $theirs = $this->givenSpace('Le leur', '39860733100024');
        $this->givenScheduledItem($mine, 'Publication du mien');
        $this->givenScheduledItem($theirs, 'Publication du leur');

        $member = $this->givenTeammate(['studio.spaces.view', 'planning.calendars.view']);
        $this->givenMembership($mine, $member);
        $this->client->loginUser($member, 'admin');

        $window = sprintf('from=%s&to=%s', urlencode(new DateTimeImmutable('-1 day')->format(DATE_ATOM)), urlencode(new DateTimeImmutable('+30 days')->format(DATE_ATOM)));
        $this->client->request('GET', '/suite/planning/events?'.$window);
        self::assertResponseIsSuccessful();

        $titles = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['events'], 'title');
        self::assertContains('Publication du mien', $titles);
        self::assertNotContains('Publication du leur', $titles);
    }

    /** Renaming, archiving or deleting a space moves its dates with it. */
    public function testASpacesDatesFollowTheSpace(): void
    {
        $space = $this->givenSpace('Avant', '73282932000074');
        $itemId = $this->givenScheduledItem($space, 'Publication');
        $events = static::getContainer()->get(PlanningEventRepository::class);

        $update = fn (string $name, string $status): array => [
            'name' => $name,
            'customerId' => $space->getCustomer()->getId(),
            'timezone' => 'Europe/Paris',
            'status' => $status,
        ];

        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/update', $space->getId()), $update('Après', 'active'));
        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertSame('Après', $events->findBySource(SpaceContentItemManager::SCHEDULE_SOURCE, $itemId)?->getSourceLabel());

        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/update', $space->getId()), $update('Après', 'archived'));
        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertNull($events->findBySource(SpaceContentItemManager::SCHEDULE_SOURCE, $itemId), 'an archived space leaves the calendar');

        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/update', $space->getId()), $update('Après', 'active'));
        $this->client->jsonRequest('POST', sprintf('/suite/studio/spaces/%d/delete', $space->getId()));
        self::assertResponseIsSuccessful();
        $this->entityManager->clear();
        self::assertNull($events->findBySource(SpaceContentItemManager::SCHEDULE_SOURCE, $itemId), 'a deleted space leaves the calendar');
    }

    /**
     * A tile of the dashboard opens every card in its state, whatever its
     * month: a publication missed last month was not on this month's grid.
     */
    public function testAStateListsItsCardsAcrossMonths(): void
    {
        $space = $this->givenSpace('Le mien', '73282932000074');
        $this->givenScheduledItem($space, 'Manquée il y a deux mois', '-60 days');
        $this->givenScheduledItem($space, 'À venir', '+3 days');

        $this->client->request('GET', '/suite/studio/calendar/items?scope=all&state=missed');
        self::assertResponseIsSuccessful();

        self::assertSame(['Manquée il y a deux mois'], array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'title'));
    }

    /**
     * The header of a space lists the other spaces the reader may open - not
     * the ones they may not, nor the archived ones - on the same tab.
     */
    public function testTheSpaceSwitcherListsTheOtherVisibleSpaces(): void
    {
        $mine = $this->givenSpace('Le mien', '73282932000074');
        $other = $this->givenSpace('Mon autre', '39860733100024');
        $theirs = $this->givenSpace('Le leur', '55210055400013');
        $archived = $this->givenSpace('Archivé', '44306184100047');
        $archived->setStatus(CustomerSpaceStatusEnum::Archived);
        $this->entityManager->flush();

        $member = $this->givenTeammate(['studio.spaces.view']);
        foreach ([$mine, $other, $archived] as $space) {
            $this->givenMembership($space, $member);
        }
        $this->client->loginUser($member, 'admin');

        $this->client->request('GET', sprintf('/workspace/%d', $mine->getId()));
        self::assertResponseIsSuccessful();
        $switcher = $this->client->getCrawler()->filter('header details a')->each(static fn ($link): string => (string) $link->attr('href'));

        self::assertSame([sprintf('/workspace/%d', $other->getId())], $switcher);
    }

    /** The steps of a board are reordered through the route the board now calls. */
    public function testTheStepsOfABoardAreReordered(): void
    {
        $space = $this->givenSpace('Tableau', '73282932000074');
        $ids = array_map(static fn ($column): int => (int) $column->getId(), static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($space));
        $reversed = array_reverse($ids);

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/columns/reorder', $space->getId()), ['columnIds' => $reversed]);
        self::assertResponseIsSuccessful();

        $this->entityManager->clear();
        $space = $this->entityManager->find(CustomerSpace::class, $space->getId());
        self::assertSame($reversed, array_map(static fn ($column): int => (int) $column->getId(), static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($space)));
    }

    /** @return list<string> */
    private function calendarTitles(string $scope): array
    {
        $query = sprintf('scope=%s&from=%s&to=%s', $scope, urlencode(new DateTimeImmutable('-1 day')->format(DATE_ATOM)), urlencode(new DateTimeImmutable('+30 days')->format(DATE_ATOM)));
        $this->client->request('GET', '/suite/studio/calendar/items?'.$query);
        self::assertResponseIsSuccessful();

        $titles = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['items'], 'title');
        sort($titles);

        return $titles;
    }

    /** @return array<string, mixed> */
    private function dashboardProps(): array
    {
        $this->client->request('GET', '/suite');
        self::assertResponseIsSuccessful();

        $node = $this->client->getCrawler()->filter('[data-symfony--ux-vue--vue-component-value="general/suite/dashboard/DashboardApp"]');

        return json_decode((string) $node->attr('data-symfony--ux-vue--vue-props-value'), true, flags: JSON_THROW_ON_ERROR);
    }

    private function givenSpace(string $name, string $siret): CustomerSpace
    {
        $customer = new Customer();
        $customer->setLegalName('Client '.$name)->setSiret($siret)->setContractualEmail('transverse@example.test');
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/suite/studio/spaces/create', ['name' => $name, 'customerId' => $customer->getId(), 'timezone' => 'Europe/Paris']);
        self::assertResponseIsSuccessful();

        $space = $this->entityManager->find(CustomerSpace::class, json_decode((string) $this->client->getResponse()->getContent(), true)['space']['id']);
        self::assertInstanceOf(CustomerSpace::class, $space);

        return $space;
    }

    /** A dated card, created through the space so the calendar hears of it. */
    private function givenScheduledItem(CustomerSpace $space, string $title, string $when = '+3 days'): int
    {
        $column = static::getContainer()->get(SpaceContentColumnRepository::class)->findForSpace($space)[0];

        $this->client->jsonRequest('POST', sprintf('/workspace/%d/content/create', $space->getId()), [
            'title' => $title,
            'columnId' => $column->getId(),
            'scheduledAt' => new DateTimeImmutable($when)->format('Y-m-d\TH:i'),
        ]);
        self::assertResponseIsSuccessful();

        foreach (json_decode((string) $this->client->getResponse()->getContent(), true)['items'] as $item) {
            if ($item['title'] === $title) {
                return (int) $item['id'];
            }
        }

        self::fail('the card is not in the answer');
    }

    /** @param list<string> $privileges */
    private function givenTeammate(array $privileges): User
    {
        $user = new User();
        $user->setEmail(sprintf('transverse-%s@example.test', bin2hex(random_bytes(3))))->setName('Équipier')
            ->setType(UserTypeEnum::Suite)->setRoles([UserRoleEnum::User->value])->setPassword('x')
            ->setPrivileges($privileges);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function givenMembership(CustomerSpace $space, User $user): void
    {
        $member = new CustomerSpaceMember();
        $member->setSpace($this->entityManager->find(CustomerSpace::class, $space->getId()))
            ->setUser($this->entityManager->find(User::class, $user->getId()))
            ->setRole(CustomerSpaceMemberRoleEnum::Member);
        $this->entityManager->persist($member);
        $this->entityManager->flush();
    }
}
