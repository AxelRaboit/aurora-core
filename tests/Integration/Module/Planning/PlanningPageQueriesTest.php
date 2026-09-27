<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Core\Notification\Entity\Notification;
use Aurora\Module\Planning\Event\Dto\PlanningEventInput;
use Aurora\Module\Planning\Event\Manager\PlanningEventManagerInterface;
use Aurora\Module\Planning\Link\Enum\PlanningShareLinkModeEnum;
use Aurora\Module\Planning\Link\Manager\PlanningShareLinkManagerInterface;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Share\Entity\PlanningShare;
use Aurora\Module\Planning\View\PlanningViewBuilder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Tests\Integration\Concern\CreatesTestUsers;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

use function array_filter;
use function array_map;
use function array_reverse;
use function array_values;
use function count;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * The calendar page and an invitation read their people in one go.
 *
 * The page loaded each calendar's shares, the person of each share and each
 * link's calendars one row at a time; inviting people found them one by one
 * and wrote each invitation's notification on its own.
 */
final class PlanningPageQueriesTest extends IntegrationTestCase
{
    use CreatesTestUsers;

    private EntityManagerInterface $entityManager;

    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::bootKernel();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->entityManager->createQuery(sprintf("DELETE FROM %s n WHERE n.type = 'planning.invitation'", Notification::class))->execute();
        foreach (array_reverse($this->created) as $entity) {
            $managed = $this->entityManager->find($entity::class, $entity->getId());
            if (null !== $managed) {
                $this->entityManager->remove($managed);
            }
        }
        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testTheCalendarPageLoadsNobodyOneByOne(): void
    {
        $owner = $this->person('proprio');
        $guests = [$this->person('invite-un'), $this->person('invite-deux')];

        $calendars = [];
        foreach (['Travail', 'Tournages', 'Perso'] as $name) {
            $planning = new Planning();
            $planning->setName($name)->setOwner($owner);
            foreach ($guests as $guest) {
                $share = new PlanningShare();
                $share->setUser($guest)->setCanWrite(false);
                $planning->addShare($share);
                $this->entityManager->persist($share);
            }
            $this->entityManager->persist($planning);
            $this->entityManager->flush();
            $this->created[] = $planning;
            $calendars[] = $planning;
        }
        $link = static::getContainer()->get(PlanningShareLinkManagerInterface::class)->create([$calendars[0], $calendars[1]], 'Deux', PlanningShareLinkModeEnum::Ics, null);
        $this->created[] = $link;

        $this->entityManager->clear();
        $owner = $this->entityManager->find(User::class, $owner->getId());
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $view = static::getContainer()->get(PlanningViewBuilder::class)->calendarView($owner);

        $mine = array_values(array_filter($view['calendars'], static fn (array $calendar): bool => 'Travail' === $calendar['name']));
        self::assertCount(2, $mine[0]['shares']);
        self::assertCount(2, array_values(array_filter($view['shareLinks'], static fn (array $one): bool => 'Deux' === $one['label']))[0]['calendars']);

        // `t0` is the alias of Doctrine's one-row loads - and of `findBy()`,
        // which the page uses once for the people it offers to invite.
        $oneByOne = array_filter(
            $holder->getData()['default'] ?? [],
            static fn (array $query): bool => 1 === preg_match('/FROM (core_planning_shares|core_users|core_plannings) t0 /', (string) $query['sql'])
                && !str_contains((string) $query['sql'], 't0.type = ?'),
        );
        self::assertSame([], array_map(static fn (array $query): string => (string) $query['sql'], array_values($oneByOne)));
    }

    public function testInvitingThreePeopleFindsThemTogether(): void
    {
        $owner = $this->person('organise');
        $people = [$this->person('a'), $this->person('b'), $this->person('c')];
        $planning = new Planning();
        $planning->setName('Réunions')->setOwner($owner);
        $this->entityManager->persist($planning);
        $this->entityManager->flush();
        $this->created[] = $planning;

        // As in a request: nobody is in memory yet.
        $ids = array_map(static fn (User $person): int => (int) $person->getId(), $people);
        $this->entityManager->clear();
        $planning = $this->entityManager->find(Planning::class, $planning->getId());
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        static::getContainer()->get(PlanningEventManagerInterface::class)->create(new PlanningEventInput(
            planningId: (int) $planning->getId(),
            title: 'Point',
            startAt: new DateTimeImmutable('2026-10-05 10:00'),
            endAt: new DateTimeImmutable('2026-10-05 11:00'),
            attendeeIds: $ids,
        ), $planning);

        $queries = $holder->getData()['default'] ?? [];
        $userReads = array_filter($queries, static fn (array $query): bool => str_contains((string) $query['sql'], 'FROM core_users'));
        $notificationWrites = array_filter($queries, static fn (array $query): bool => str_contains((string) $query['sql'], 'INSERT INTO core_notifications'));

        self::assertSame(1, count($userReads), 'the three people, found together');
        self::assertSame(3, count($notificationWrites));
    }

    private function person(string $name): User
    {
        $user = $this->createTestUser($name);
        $this->created[] = $user;

        return $user;
    }
}
