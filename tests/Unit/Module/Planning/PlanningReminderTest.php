<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventReminder;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * What a reminder computes, and what it refuses.
 *
 * The due time is stored, not derived on read, so the thing to test is that it
 * is right at every moment it could be written: when the reminder is attached,
 * when its offset changes, and when the event moves underneath it.
 */
final class PlanningReminderTest extends TestCase
{
    public function testAttachingAReminderComputesWhenItIsDue(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $reminder = new PlanningEventReminder();
        $reminder->setMinutesBefore(30);
        $event->addReminder($reminder);

        self::assertSame('2026-08-23 13:30', $reminder->getRemindAt()->format('Y-m-d H:i'));
    }

    public function testAtStartMeansTheStart(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $reminder = new PlanningEventReminder();
        $reminder->setMinutesBefore(0);
        $event->addReminder($reminder);

        self::assertSame('2026-08-23 14:00', $reminder->getRemindAt()->format('Y-m-d H:i'));
    }

    /**
     * The one that makes storing remindAt a decision rather than a shortcut.
     *
     * Move the event and every reminder has to move with it. A derived due time
     * would get this for free; a stored one has to be told, and this is the test
     * that says it was.
     */
    public function testMovingTheEventMovesItsReminders(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $reminder = new PlanningEventReminder();
        $reminder->setMinutesBefore(60);
        $event->addReminder($reminder);

        $event->setSpan(new DateTimeImmutable('2026-08-25 09:00'), new DateTimeImmutable('2026-08-25 10:00'));

        self::assertSame('2026-08-25 08:00', $reminder->getRemindAt()->format('Y-m-d H:i'));
    }

    public function testAWeekBeforeCrossesTheMonth(): void
    {
        $event = $this->eventAt('2026-09-02 09:00');

        $reminder = new PlanningEventReminder();
        $reminder->setMinutesBefore(10080);
        $event->addReminder($reminder);

        self::assertSame('2026-08-26 09:00', $reminder->getRemindAt()->format('Y-m-d H:i'));
    }

    /**
     * Refused, where a colour slot out of range is clamped.
     *
     * The difference is that a clamped colour is still a colour, and a clamped
     * offset would be a reminder at a time nobody asked for - which the reader
     * discovers by being told about their event at the wrong moment.
     */
    public function testAnOffsetOutsideTheListIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PlanningEventReminder())->setMinutesBefore(7);
    }

    public function testEveryOfferedOffsetIsAccepted(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        foreach (PlanningEventReminder::OFFSETS as $offset) {
            $reminder = new PlanningEventReminder();
            $reminder->setMinutesBefore($offset);
            $event->addReminder($reminder);

            self::assertSame($offset, $reminder->getMinutesBefore());
        }

        self::assertCount(count(PlanningEventReminder::OFFSETS), $event->getReminders());
    }

    public function testTheDefaultOffsetIsOneTheListOffers(): void
    {
        self::assertContains(PlanningEventReminder::DEFAULT_OFFSET, PlanningEventReminder::OFFSETS);
    }

    /**
     * The form draws its chips from a copy of this list in JavaScript, because
     * asking the server for nine integers before it can draw a control would be
     * a round trip for a constant. This is the test that keeps the copy honest.
     */
    public function testTheFormOffersExactlyTheOffsetsTheEntityAccepts(): void
    {
        $js = file_get_contents(__DIR__.'/../../../../src/Module/Planning/assets/backend/planning/composables/reminderOffsets.js');
        self::assertIsString($js);

        self::assertSame(1, preg_match('/REMINDER_OFFSETS = \[([^\]]+)\]/', $js, $matches));
        $offsets = array_map(static fn (string $value): int => (int) mb_trim($value), explode(',', $matches[1]));

        self::assertSame(PlanningEventReminder::OFFSETS, $offsets);
        self::assertStringContainsString(
            sprintf('DEFAULT_REMINDER_OFFSET = %d', PlanningEventReminder::DEFAULT_OFFSET),
            $js,
        );
    }

    public function testMarkingSentRecordsWhenAndNotJustThat(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');
        $reminder = new PlanningEventReminder();
        $reminder->setMinutesBefore(15);
        $event->addReminder($reminder);

        self::assertNull($reminder->getSentAt());

        $at = new DateTimeImmutable('2026-08-23 13:45');
        $reminder->markSent($at);

        self::assertSame($at->format(DATE_ATOM), $reminder->getSentAt()?->format(DATE_ATOM));
    }

    private function eventAt(string $start): PlanningEvent
    {
        $event = new PlanningEvent();
        $startAt = new DateTimeImmutable($start);
        $event->setTitle('Réunion');
        $event->setSpan($startAt, $startAt->modify('+1 hour'));

        return $event;
    }
}
