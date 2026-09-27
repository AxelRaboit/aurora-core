<?php

declare(strict_types=1);

namespace Aurora\Tests\Unit\Module\Planning\Reminder;

use Aurora\Module\Planning\Reminder\Entity\PlanningReminder;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * « Late » for a whole-day reminder means its day is over, not that its day
 * has begun: « buy bread today » was red from midnight on.
 */
final class PlanningReminderOverdueTest extends TestCase
{
    public function testAWholeDayReminderIsLateOnlyOnceItsDayIsOver(): void
    {
        $reminder = new PlanningReminder();
        $reminder->setTitle('Pain')->setAllDay(true)->setDueAt(new DateTimeImmutable('2026-09-27 00:00 UTC'));

        self::assertFalse($reminder->isOverdue(new DateTimeImmutable('2026-09-27 18:00 UTC')), 'during its day');
        self::assertTrue($reminder->isOverdue(new DateTimeImmutable('2026-09-28 00:00 UTC')), 'the next day');
    }

    public function testATimedReminderIsLateOnceItsTimeHasPassed(): void
    {
        $reminder = new PlanningReminder();
        $reminder->setTitle('Appel')->setDueAt(new DateTimeImmutable('2026-09-27 10:00 UTC'));

        self::assertFalse($reminder->isOverdue(new DateTimeImmutable('2026-09-27 09:59 UTC')));
        self::assertTrue($reminder->isOverdue(new DateTimeImmutable('2026-09-27 10:01 UTC')));

        $reminder->complete(new DateTimeImmutable('2026-09-27 11:00 UTC'));
        self::assertFalse($reminder->isOverdue(new DateTimeImmutable('2026-09-28 10:00 UTC')), 'done is never late');
    }
}
