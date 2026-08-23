<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Event\Entity\PlanningEvent;
use Aurora\Module\Planning\Event\Entity\PlanningEventAlert;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * What an alert computes, and what it refuses.
 *
 * The due time is stored, not derived on read, so the thing to test is that it
 * is right at every moment it could be written: when the alert is attached,
 * when its offset changes, and when the event moves underneath it.
 */
final class PlanningEventAlertTest extends TestCase
{
    public function testAttachingAAlertComputesWhenItIsDue(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore(30);
        $event->addAlert($alert);

        self::assertSame('2026-08-23 13:30', $alert->getRemindAt()->format('Y-m-d H:i'));
    }

    public function testAtStartMeansTheStart(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore(0);
        $event->addAlert($alert);

        self::assertSame('2026-08-23 14:00', $alert->getRemindAt()->format('Y-m-d H:i'));
    }

    /**
     * The one that makes storing remindAt a decision rather than a shortcut.
     *
     * Move the event and every alert has to move with it. A derived due time
     * would get this for free; a stored one has to be told, and this is the test
     * that says it was.
     */
    public function testMovingTheEventMovesItsAlerts(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore(60);
        $event->addAlert($alert);

        $event->setSpan(new DateTimeImmutable('2026-08-25 09:00'), new DateTimeImmutable('2026-08-25 10:00'));

        self::assertSame('2026-08-25 08:00', $alert->getRemindAt()->format('Y-m-d H:i'));
    }

    public function testAWeekBeforeCrossesTheMonth(): void
    {
        $event = $this->eventAt('2026-09-02 09:00');

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore(10080);
        $event->addAlert($alert);

        self::assertSame('2026-08-26 09:00', $alert->getRemindAt()->format('Y-m-d H:i'));
    }

    /**
     * Refused, where a colour slot out of range is clamped.
     *
     * The difference is that a clamped colour is still a colour, and a clamped
     * offset would be an alert at a time nobody asked for - which the reader
     * discovers by being told about their event at the wrong moment.
     */
    public function testAnOffsetOutsideTheListIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PlanningEventAlert())->setMinutesBefore(7);
    }

    public function testEveryOfferedOffsetIsAccepted(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        foreach (PlanningEventAlert::OFFSETS as $offset) {
            $alert = new PlanningEventAlert();
            $alert->setMinutesBefore($offset);
            $event->addAlert($alert);

            self::assertSame($offset, $alert->getMinutesBefore());
        }

        self::assertCount(count(PlanningEventAlert::OFFSETS), $event->getAlerts());
    }

    public function testTheDefaultOffsetIsOneTheListOffers(): void
    {
        self::assertContains(PlanningEventAlert::DEFAULT_OFFSET, PlanningEventAlert::OFFSETS);
    }

    /**
     * The form draws its chips from a copy of this list in JavaScript, because
     * asking the server for nine integers before it can draw a control would be
     * a round trip for a constant. This is the test that keeps the copy honest.
     */
    public function testTheFormOffersExactlyTheOffsetsTheEntityAccepts(): void
    {
        $js = file_get_contents(__DIR__.'/../../../../src/Module/Planning/assets/backend/planning/composables/alertOffsets.js');
        self::assertIsString($js);

        self::assertSame(1, preg_match('/ALERT_OFFSETS = \[([^\]]+)\]/', $js, $matches));
        $offsets = array_map(static fn (string $value): int => (int) mb_trim($value), explode(',', $matches[1]));

        self::assertSame(PlanningEventAlert::OFFSETS, $offsets);
        self::assertStringContainsString(
            sprintf('DEFAULT_ALERT_OFFSET = %d', PlanningEventAlert::DEFAULT_OFFSET),
            $js,
        );
    }

    public function testMarkingSentRecordsWhenAndNotJustThat(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');
        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore(15);
        $event->addAlert($alert);

        self::assertNull($alert->getSentAt());

        $at = new DateTimeImmutable('2026-08-23 13:45');
        $alert->markSent($at);

        self::assertSame($at->format(DATE_ATOM), $alert->getSentAt()?->format(DATE_ATOM));
    }

    /**
     * The rule that separates the two kinds, and the reason the offset is
     * nullable at all.
     *
     * A relative alert follows its event; one somebody pinned to Tuesday at 09:00
     * stays there. Moving it because the meeting moved would take it away from
     * the reader who chose it.
     */
    public function testAPinnedAlertDoesNotFollowTheEvent(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $pinned = new PlanningEventAlert();
        $event->addAlert($pinned);
        $pinned->setAbsoluteAt(new DateTimeImmutable('2026-08-22 09:00'));

        $event->setSpan(new DateTimeImmutable('2026-08-25 09:00'), new DateTimeImmutable('2026-08-25 10:00'));

        self::assertSame('2026-08-22 09:00', $pinned->getRemindAt()->format('Y-m-d H:i'));
        self::assertNull($pinned->getMinutesBefore());
        self::assertFalse($pinned->isRelative());
    }

    public function testBothKindsCanSitOnOneEvent(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $relative = new PlanningEventAlert();
        $relative->setMinutesBefore(30);
        $event->addAlert($relative);

        $pinned = new PlanningEventAlert();
        $event->addAlert($pinned);
        $pinned->setAbsoluteAt(new DateTimeImmutable('2026-08-22 09:00'));

        $event->setSpan(new DateTimeImmutable('2026-08-25 09:00'), new DateTimeImmutable('2026-08-25 10:00'));

        // One moved with the event, the other did not.
        self::assertSame('2026-08-25 08:30', $relative->getRemindAt()->format('Y-m-d H:i'));
        self::assertSame('2026-08-22 09:00', $pinned->getRemindAt()->format('Y-m-d H:i'));
    }

    /**
     * Pinning writes the moment rather than converting it to an offset.
     *
     * An offset would be recomputed the next time the event moved, which is the
     * defect the test above catches - this one says the storage is right, not just
     * the behaviour.
     */
    public function testPinningClearsTheOffset(): void
    {
        $event = $this->eventAt('2026-08-23 14:00');

        $alert = new PlanningEventAlert();
        $alert->setMinutesBefore(30);
        $event->addAlert($alert);
        self::assertTrue($alert->isRelative());

        $alert->setAbsoluteAt(new DateTimeImmutable('2026-08-22 09:00'));

        self::assertNull($alert->getMinutesBefore());
        self::assertSame('2026-08-22 09:00', $alert->getRemindAt()->format('Y-m-d H:i'));
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
