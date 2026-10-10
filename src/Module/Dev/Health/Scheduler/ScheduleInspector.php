<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Scheduler;

use Aurora\Core\Scheduler\MainSchedule;
use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use DateTimeImmutable;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;
use Throwable;

/**
 * Every scheduled task, its rhythm, its next run and how its last run went.
 *
 * **Late is measured against the task's own rhythm.** A task « every minute »
 * silent for ten minutes has stopped; a task « every night » silent for ten
 * hours has not. The rhythm is read off the trigger itself (the gap between
 * its next two runs), so a task added tomorrow is judged without anybody
 * writing its period here.
 *
 * A task that never ran since the cache was cleared is « not seen yet »,
 * not late: a deployment empties the cache, and a nightly task will only be
 * seen the next night.
 */
final readonly class ScheduleInspector
{
    /** Slack before a task counts as late, in seconds: the worker loops, it does not tick. */
    private const int GRACE_SECONDS = 180;

    public function __construct(
        private MainSchedule $schedule,
        private ScheduleRunRecorder $recorder,
    ) {}

    /** @return list<array<string, mixed>> */
    public function tasks(): array
    {
        $now = new DateTimeImmutable();
        $tasks = [];

        try {
            $messages = $this->schedule->getSchedule()->getRecurringMessages();
        } catch (Throwable) {
            return [];
        }

        foreach ($messages as $recurring) {
            $tasks[] = $this->describe($recurring, $now);
        }

        usort($tasks, static fn (array $left, array $right): int => $left['periodSeconds'] <=> $right['periodSeconds'] ?: strcmp($left['name'], $right['name']));

        return $tasks;
    }

    /** @return array<string, mixed> */
    private function describe(RecurringMessage $recurring, DateTimeImmutable $now): array
    {
        $trigger = $recurring->getTrigger();
        $next = $trigger->getNextRunDate($now);
        $afterNext = $next instanceof DateTimeImmutable ? $trigger->getNextRunDate($next->modify('+1 second')) : null;
        $period = $next instanceof DateTimeImmutable && $afterNext instanceof DateTimeImmutable ? max(60, $afterNext->getTimestamp() - $next->getTimestamp()) : 86400;

        $last = $this->recorder->lastRunOf($recurring->getId());

        $status = match (true) {
            null === $last => HealthStatusEnum::Unknown,
            $last['failed'] => HealthStatusEnum::Danger,
            $now->getTimestamp() - $last['at']->getTimestamp() > $period + max(self::GRACE_SECONDS, intdiv($period, 2)) => HealthStatusEnum::Danger,
            default => HealthStatusEnum::Ok,
        };

        return [
            'id' => $recurring->getId(),
            'name' => $this->nameOf($recurring, $now),
            'trigger' => (string) $trigger,
            'periodSeconds' => $period,
            'nextRunAt' => $next?->format(DATE_ATOM),
            'lastRunAt' => null !== $last ? $last['at']->format(DATE_ATOM) : null,
            'error' => $last['error'] ?? null,
            'status' => $status->value,
        ];
    }

    /** The message's short class name, « CleanTempFilesMessage » without its namespace. */
    private function nameOf(RecurringMessage $recurring, DateTimeImmutable $now): string
    {
        try {
            $context = new MessageContext('main', $recurring->getId(), $recurring->getTrigger(), $now);
            foreach ($recurring->getProvider()->getMessages($context) as $message) {
                $class = $message::class;

                return mb_substr($class, (int) mb_strrpos($class, '\\') + 1);
            }
        } catch (Throwable) {
        }

        return $recurring->getId();
    }
}
