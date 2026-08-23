<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
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
        $found = $this->events->findInWindow(
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

        self::assertSame([], $this->events->findInWindow(
            [],
            new DateTimeImmutable('2026-08-01 00:00'),
            new DateTimeImmutable('2026-09-01 00:00'),
        ));
    }
}
