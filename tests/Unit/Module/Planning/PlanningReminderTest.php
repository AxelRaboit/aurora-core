<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning;

use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * What separates a reminder from an event.
 *
 * An event has two ends and occupies them; a reminder has one moment and a state.
 * These are the tests for the state, because that is the part an event has no
 * equivalent of and therefore the part with nothing already proving it.
 */
final class PlanningReminderTest extends TestCase
{
    public function testANewReminderIsNotDone(): void
    {
        self::assertFalse($this->reminder()->isCompleted());
        self::assertNull($this->reminder()->getCompletedAt());
    }

    public function testCompletingRecordsWhenAndNotJustThat(): void
    {
        $reminder = $this->reminder();
        $at = new DateTimeImmutable('2026-09-01 11:20');

        $reminder->complete($at);

        self::assertTrue($reminder->isCompleted());
        self::assertSame($at->format(DATE_ATOM), $reminder->getCompletedAt()?->format(DATE_ATOM));
    }

    public function testReopeningForgetsThatItWasDone(): void
    {
        $reminder = $this->reminder();
        $reminder->complete(new DateTimeImmutable('2026-09-01 11:20'));

        $reminder->reopen();

        self::assertFalse($reminder->isCompleted());
        self::assertNull($reminder->getCompletedAt());
    }

    /**
     * The one that makes deferring work.
     *
     * Pushing a reminder to next week has to make it arrive again. Without this,
     * the row is already stamped as announced and nothing would ever mention the
     * new date - and deferring is the most common thing anybody does to a
     * reminder, so getting it wrong makes the feature quietly useless.
     */
    public function testMovingTheDueDateMakesItAnnounceableAgain(): void
    {
        $reminder = $this->reminder();
        $reminder->markNotified(new DateTimeImmutable('2026-09-01 09:00'));
        self::assertNotNull($reminder->getNotifiedAt());

        $reminder->setDueAt(new DateTimeImmutable('2026-09-08 09:00'));

        self::assertNull($reminder->getNotifiedAt());
    }

    /**
     * Saving without touching the date must not resend it.
     *
     * The other half of the rule above: if any write cleared the stamp, renaming a
     * reminder would announce it a second time.
     */
    public function testSavingTheSameDueDateKeepsItAnnounced(): void
    {
        $reminder = $this->reminder();
        $reminder->markNotified(new DateTimeImmutable('2026-09-01 09:00'));

        $reminder->setDueAt(new DateTimeImmutable('2026-09-01 09:00'));

        self::assertNotNull($reminder->getNotifiedAt());
    }

    public function testTheFirstDueDateIsNotAMove(): void
    {
        // Setting it on a fresh reminder must not read as a change of something
        // that was never there, or the guard would depend on an unset property.
        $reminder = new PlanningReminder();
        $reminder->setTitle('Appeler le client');
        $reminder->setDueAt(new DateTimeImmutable('2026-09-01 09:00'));

        self::assertNull($reminder->getNotifiedAt());
        self::assertSame('2026-09-01 09:00', $reminder->getDueAt()->format('Y-m-d H:i'));
    }

    private function reminder(): PlanningReminder
    {
        $reminder = new PlanningReminder();
        $reminder->setTitle('Appeler le client');
        $reminder->setDueAt(new DateTimeImmutable('2026-09-01 09:00'));

        return $reminder;
    }
}
