<?php

declare(strict_types=1);

namespace Aurora\Tests\Integration\Module\Planning;

use Aurora\Core\Scheduling\Event\EntityScheduledEvent;
use Aurora\Core\Scheduling\Event\EntityUnscheduledEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Repository\PlanningEventRepository;
use Aurora\Module\Planning\Planning\Entity\Planning;
use Aurora\Module\Planning\Planning\Enum\PlanningVisibilityEnum;
use Aurora\Module\Planning\Planning\Repository\PlanningRepository;
use Aurora\Tests\Integration\IntegrationTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Another module's dates, on the calendar.
 *
 * Driven through the core signal rather than through Editorial, because that is
 * the contract: any module can announce a date and the calendar picks it up
 * without either side knowing the other. A test going through posts would be
 * testing Editorial's write paths, which is a different question.
 */
final class PlanningModuleSyncTest extends IntegrationTestCase
{
    private const string SOURCE = 'test.thing';

    private EntityManagerInterface $entityManager;

    private EventDispatcherInterface $dispatcher;

    private PlanningEventRepository $events;

    private PlanningRepository $plannings;

    protected function setUp(): void
    {
        parent::setUp();
        static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $this->dispatcher = static::getContainer()->get(EventDispatcherInterface::class);
        $this->events = static::getContainer()->get(PlanningEventRepository::class);
        $this->plannings = static::getContainer()->get(PlanningRepository::class);
    }

    protected function tearDown(): void
    {
        $entry = $this->events->findBySource(self::SOURCE, 42);
        if (null !== $entry) {
            $this->entityManager->remove($entry);
        }

        $calendar = $this->plannings->findOneBy(['sourceType' => self::SOURCE]);
        if (null !== $calendar) {
            $this->entityManager->remove($calendar);
        }

        $this->entityManager->flush();

        parent::tearDown();
    }

    public function testAnAnnouncedDateAppearsOnAModuleCalendar(): void
    {
        $this->dispatcher->dispatch($this->scheduled('Le truc', '2026-09-14 09:00'));

        $entry = $this->events->findBySource(self::SOURCE, 42);
        self::assertInstanceOf(PlanningEvent::class, $entry);
        self::assertSame('Le truc', $entry->getTitle());
        self::assertSame('2026-09-14 09:00', $entry->getStartAt()->format('Y-m-d H:i'));

        // No end announced means a moment, and a moment is a span of zero.
        self::assertSame($entry->getStartAt()->getTimestamp(), $entry->getEndAt()->getTimestamp());

        $calendar = $entry->getPlanning();
        self::assertSame(self::SOURCE, $calendar->getSourceType());
        self::assertTrue($calendar->isFromModule());
    }

    /**
     * Shared and ownerless, and both halves matter.
     *
     * Ownerless because nobody made it, so nobody should find it under "my
     * calendars" as though they had. Shared because `findVisibleTo` returns
     * calendars you own or that are shared - a private ownerless calendar is
     * visible to no one, which this module has already got wrong once in a
     * fixture.
     */
    public function testTheModuleCalendarIsSharedAndBelongsToNobody(): void
    {
        $this->dispatcher->dispatch($this->scheduled('Le truc', '2026-09-14 09:00'));

        $calendar = $this->plannings->findOneBy(['sourceType' => self::SOURCE]);
        self::assertInstanceOf(Planning::class, $calendar);
        self::assertNull($calendar->getOwner());
        self::assertSame(PlanningVisibilityEnum::Shared, $calendar->getVisibility());
    }

    /**
     * Announcing the same thing twice moves one entry rather than making two.
     *
     * The upsert is keyed on `(sourceType, sourceId)`, which the events table
     * already held unique - so getting this wrong would not be a duplicate row,
     * it would be a constraint violation on a perfectly ordinary save.
     */
    public function testAnnouncingTheSameEntityAgainMovesItsEntry(): void
    {
        $this->dispatcher->dispatch($this->scheduled('Le truc', '2026-09-14 09:00'));
        $this->dispatcher->dispatch($this->scheduled('Le truc, déplacé', '2026-09-20 15:30'));

        $entry = $this->events->findBySource(self::SOURCE, 42);
        self::assertInstanceOf(PlanningEvent::class, $entry);
        self::assertSame('Le truc, déplacé', $entry->getTitle());
        self::assertSame('2026-09-20 15:30', $entry->getStartAt()->format('Y-m-d H:i'));

        self::assertCount(1, $this->plannings->findBy(['sourceType' => self::SOURCE]));
    }

    public function testAnUnscheduledEntityLeavesTheCalendar(): void
    {
        $this->dispatcher->dispatch($this->scheduled('Le truc', '2026-09-14 09:00'));
        self::assertNotNull($this->events->findBySource(self::SOURCE, 42));

        $this->dispatcher->dispatch(new EntityUnscheduledEvent(self::SOURCE, 42));

        self::assertNull($this->events->findBySource(self::SOURCE, 42));
    }

    /**
     * Unscheduling something that was never on the calendar is not an error.
     *
     * A module saying "this has no date" has no way to know whether it ever did,
     * and making it find out first would be a query on every save.
     */
    public function testUnschedulingSomethingUnknownIsQuiet(): void
    {
        $this->dispatcher->dispatch(new EntityUnscheduledEvent(self::SOURCE, 42));

        self::assertNull($this->events->findBySource(self::SOURCE, 42));
    }

    /**
     * A synced entry is not ours to edit, and the entity says so itself.
     *
     * That flag is what the manager refuses on and what the screen hides its
     * buttons for, so the sync gets both for free by setting the source.
     */
    public function testASyncedEntryIsReadOnlyAndPointsAtItsSource(): void
    {
        $this->dispatcher->dispatch($this->scheduled('Le truc', '2026-09-14 09:00'));

        $entry = $this->events->findBySource(self::SOURCE, 42);
        self::assertInstanceOf(PlanningEvent::class, $entry);
        self::assertTrue($entry->isFromModule());
        self::assertSame('Tests', $entry->getSourceLabel());
        self::assertSame('/quelque-part/42', $entry->getSourceUrl());
    }

    /**
     * The module renames its own calendar and the rename lands.
     *
     * A translation changing should not leave the old wording on screen for ever.
     */
    public function testTheModuleCanRenameItsCalendar(): void
    {
        $this->dispatcher->dispatch($this->scheduled('Le truc', '2026-09-14 09:00'));
        $this->dispatcher->dispatch(new EntityScheduledEvent(
            sourceType: self::SOURCE,
            sourceId: 42,
            label: 'Le truc',
            startAt: new DateTimeImmutable('2026-09-14 09:00'),
            calendarName: 'Choses testées',
        ));

        $calendar = $this->plannings->findOneBy(['sourceType' => self::SOURCE]);
        self::assertInstanceOf(Planning::class, $calendar);
        self::assertSame('Choses testées', $calendar->getName());
    }

    private function scheduled(string $label, string $startAt): EntityScheduledEvent
    {
        return new EntityScheduledEvent(
            sourceType: self::SOURCE,
            sourceId: 42,
            label: $label,
            startAt: new DateTimeImmutable($startAt),
            calendarName: 'Choses',
            sourceLabel: 'Tests',
            url: '/quelque-part/42',
        );
    }
}
