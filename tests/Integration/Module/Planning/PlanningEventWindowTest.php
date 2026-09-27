<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Attendee\Entity\PlanningEventAttendee;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Event\Serializer\PlanningEventSerializer;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Recurrence\PlanningOccurrenceFinder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Repository\UserRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The window query, and the bug every calendar has once.
 *
 * A month grid asks "what is visible in August". The tempting condition is
 * `start BETWEEN august_first AND august_last`, and it is wrong: a week of
 * holiday that began in July and runs into August has a start outside the window
 * and belongs on the grid all the same. It disappears, and nothing errors.
 *
 * So the repository asks for overlap - `start < windowEnd AND end > windowStart`
 * - and this asserts each side of it separately, because a query can catch one
 * and miss the other.
 */
final class PlanningEventWindowTest extends IntegrationTestCase
{
    private EntityManagerInterface $entityManager;

    private PlanningEventRepository $events;

    private Planning $planning;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->events = static::getContainer()->get(PlanningEventRepository::class);

        $this->planning = new Planning();
        $this->planning->setName('Test');
        $this->entityManager->persist($this->planning);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        // Removed rather than left behind: the calendar list is a sidebar
        // somebody reads, and a test that seeds one every run makes the next
        // test's fixtures unreadable.
        $this->entityManager->remove($this->planning);
        $this->entityManager->flush();

        parent::tearDown();
    }

    private function event(string $title, string $start, string $end): PlanningEvent
    {
        $event = new PlanningEvent();
        $event->setTitle($title)
            ->setPlanning($this->planning)
            ->setSpan(new DateTimeImmutable($start), new DateTimeImmutable($end));

        $this->entityManager->persist($event);
        $this->entityManager->flush();

        return $event;
    }

    /**
     * @return list<string>
     */
    private function titlesInAugust(): array
    {
        $found = $this->events->findSinglesInWindow(
            [(int) $this->planning->getId()],
            new DateTimeImmutable('2026-08-01 00:00'),
            new DateTimeImmutable('2026-09-01 00:00'),
        );

        return array_map(static fn (object $e): string => $e->getTitle(), $found);
    }

    public function testAnEventInsideTheWindowIsFound(): void
    {
        $this->event('Réunion', '2026-08-14 14:00', '2026-08-14 15:30');

        self::assertSame(['Réunion'], $this->titlesInAugust());
    }

    /**
     * The case a `BETWEEN` on the start drops. This is the whole reason the
     * condition is written the way it is.
     */
    public function testAnEventStartingBeforeTheWindowAndRunningIntoItIsFound(): void
    {
        $this->event('Congés', '2026-07-28 00:00', '2026-08-04 00:00');

        self::assertSame(['Congés'], $this->titlesInAugust());
    }

    /** And the mirror of it, which a `BETWEEN` on the end would drop. */
    public function testAnEventRunningOutOfTheWindowIsFound(): void
    {
        $this->event('Chantier', '2026-08-28 00:00', '2026-09-06 00:00');

        self::assertSame(['Chantier'], $this->titlesInAugust());
    }

    /** One that swallows the window whole has neither end inside it. */
    public function testAnEventSpanningTheWholeWindowIsFound(): void
    {
        $this->event('Année sabbatique', '2026-01-01 00:00', '2026-12-31 00:00');

        self::assertSame(['Année sabbatique'], $this->titlesInAugust());
    }

    /**
     * The window is half-open, which is what makes two adjacent months not both
     * claim the same midnight.
     */
    public function testAnEventEndingExactlyAtTheWindowStartIsNotFound(): void
    {
        $this->event('Juillet', '2026-07-20 00:00', '2026-08-01 00:00');

        self::assertSame([], $this->titlesInAugust());
    }

    public function testAnEventStartingExactlyAtTheWindowEndIsNotFound(): void
    {
        $this->event('Septembre', '2026-09-01 00:00', '2026-09-02 00:00');

        self::assertSame([], $this->titlesInAugust());
    }

    public function testNoCalendarMeansNoQueryAndNoEvents(): void
    {
        $this->event('Réunion', '2026-08-14 14:00', '2026-08-14 15:30');

        self::assertSame([], $this->events->findSinglesInWindow(
            [],
            new DateTimeImmutable('2026-08-01 00:00'),
            new DateTimeImmutable('2026-09-01 00:00'),
        ));
    }

    /**
     * A month grid costs the same queries with three events or a hundred.
     *
     * The serializer reads each event's alerts and attendees, an edited
     * occurrence's series, and the expander each series' edited occurrences:
     * all lazy, so a query per event on the grid and on the public share page.
     * They now come with the two window queries.
     */
    public function testTheGridLoadsItsEventsWithWhatItDraws(): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'dev@aurora.app', 'type' => 'backend']);
        self::assertInstanceOf(User::class, $user);

        foreach (['4', '5', '6'] as $day) {
            $event = $this->event('Rendez-vous '.$day, "2026-08-0{$day} 10:00", "2026-08-0{$day} 11:00");
            $alert = new PlanningEventAlert();
            $alert->setMinutesBefore(15);
            $event->addAlert($alert);
            $attendee = new PlanningEventAttendee();
            $attendee->setEvent($event)->setUser($user);
            $this->entityManager->persist($attendee);
        }

        $series = $this->event('Point hebdo', '2026-08-03 09:00', '2026-08-03 09:30');
        $series->setRrule('FREQ=WEEKLY');
        $moved = $this->event('Point hebdo', '2026-08-10 14:00', '2026-08-10 14:30');
        $moved->setMaster($series)->setOccurrenceAt(new DateTimeImmutable('2026-08-10 09:00'));
        $this->entityManager->flush();

        $this->entityManager->clear();
        $holder = static::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $occurrences = static::getContainer()->get(PlanningOccurrenceFinder::class)->find(
            [(int) $this->planning->getId()],
            new DateTimeImmutable('2026-08-01 00:00'),
            new DateTimeImmutable('2026-09-01 00:00'),
        );
        $rows = static::getContainer()->get(PlanningEventSerializer::class)->serializeMany($occurrences);

        // Three meetings, the moved occurrence, and the four other Mondays.
        self::assertCount(8, $rows);
        self::assertSame(2, count($holder->getData()['default'] ?? []), 'the singles and the series, nothing more');

        // Planning is re-read by the tearDown: the clear above detached it.
        $this->planning = $this->entityManager->find(Planning::class, $this->planning->getId());
    }
}
