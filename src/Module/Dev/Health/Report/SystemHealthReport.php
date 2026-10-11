<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Report;

use Aurora\Module\Dev\Health\Enum\HealthStatusEnum;
use Aurora\Module\Dev\Health\Probe\CertificateProbe;
use Aurora\Module\Dev\Health\Probe\DatabaseProbe;
use Aurora\Module\Dev\Health\Probe\EnvironmentProbe;
use Aurora\Module\Dev\Health\Probe\MercureProbe;
use Aurora\Module\Dev\Health\Probe\SystemdProbe;
use Aurora\Module\Dev\Health\Queue\QueueInspector;
use Aurora\Module\Dev\Health\Scheduler\ScheduleInspector;
use Aurora\Module\Dev\Health\Worker\WorkerHeartbeat;
use DateTimeImmutable;

/**
 * Everything that must be running, in one answer.
 *
 * Grouped as the block draws it: what does the background work (the worker,
 * the scheduled tasks, the queues), what the application stands on (the
 * database, the live hub, the server's units), and what it runs with (its
 * version, the disk, the mail, the storage, the certificate). The summary is
 * the worst line: one red line makes the block red, because the one thing
 * stopped is the one thing worth opening it for.
 *
 * Built on demand, by the page once it is on screen: the probes make network
 * calls (the hub, the storage, the certificate) and the overview must not
 * wait for them to open.
 */
final readonly class SystemHealthReport
{
    /** A message waiting longer than this, in seconds, is one nobody consumes. */
    private const int QUEUE_STALE_AFTER = 600;

    public function __construct(
        private WorkerHeartbeat $workerHeartbeat,
        private ScheduleInspector $scheduleInspector,
        private QueueInspector $queueInspector,
        private DatabaseProbe $databaseProbe,
        private MercureProbe $mercureProbe,
        private EnvironmentProbe $environmentProbe,
        private CertificateProbe $certificateProbe,
        private SystemdProbe $systemdProbe,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $tasks = $this->scheduleInspector->tasks();

        $sections = [
            'background' => [$this->worker(), $this->scheduler($tasks), ...$this->queues()],
            'foundations' => [...$this->databaseProbe->checks(), $this->mercureProbe->check(), ...$this->systemdProbe->checks()],
            'environment' => [...$this->environmentProbe->checks(), $this->certificateProbe->check()],
        ];

        $statuses = [];
        $payload = [];
        foreach ($sections as $key => $checks) {
            $sectionStatuses = array_map(static fn (HealthCheck $check): HealthStatusEnum => $check->status, $checks);
            $statuses = [...$statuses, ...$sectionStatuses];
            $payload[] = [
                'key' => $key,
                'status' => HealthStatusEnum::worstOf($sectionStatuses)->value,
                'checks' => array_map(static fn (HealthCheck $check): array => $check->toArray(), $checks),
            ];
        }

        return [
            'status' => HealthStatusEnum::worstOf($statuses)->value,
            'checkedAt' => new DateTimeImmutable()->format(DATE_ATOM),
            'sections' => $payload,
            'tasks' => $tasks,
            'failures' => $this->queueInspector->failures(),
        ];
    }

    private function worker(): HealthCheck
    {
        $beat = $this->workerHeartbeat->last();
        if (null === $beat) {
            return new HealthCheck('worker', HealthStatusEnum::Danger, 'suite.health.worker.label', 'suite.health.worker.never_seen');
        }

        $seconds = max(0, time() - $beat['at']->getTimestamp());
        $crashedAgo = null === $beat['crashedAt'] ? null : max(0, time() - $beat['crashedAt']->getTimestamp());
        $recentCrash = null !== $crashedAgo && $crashedAgo < WorkerHeartbeat::CRASH_SHOWN_FOR;

        [$status, $message] = match (true) {
            $seconds > WorkerHeartbeat::SILENT_AFTER => [HealthStatusEnum::Danger, 'suite.health.worker.silent'],
            $recentCrash => [HealthStatusEnum::Warning, 'suite.health.worker.crashed'],
            default => [HealthStatusEnum::Ok, 'suite.health.worker.alive'],
        };

        return new HealthCheck(
            'worker',
            $status,
            'suite.health.worker.label',
            $message,
            ['seconds' => $seconds, 'hours' => intdiv($crashedAgo ?? 0, 3600)],
            [
                'seenAt' => $beat['at']->format(DATE_ATOM),
                'startedAt' => $beat['startedAt']?->format(DATE_ATOM),
                'crashedAt' => $beat['crashedAt']?->format(DATE_ATOM),
                'transports' => $beat['transports'],
                'pid' => $beat['pid'],
            ],
        );
    }

    /** @param list<array<string, mixed>> $tasks */
    private function scheduler(array $tasks): HealthCheck
    {
        $late = count(array_filter($tasks, static fn (array $task): bool => HealthStatusEnum::Danger->value === $task['status']));
        $unseen = count(array_filter($tasks, static fn (array $task): bool => HealthStatusEnum::Unknown->value === $task['status']));

        return new HealthCheck(
            'scheduler',
            $late > 0 ? HealthStatusEnum::Danger : HealthStatusEnum::Ok,
            'suite.health.scheduler.label',
            $late > 0 ? 'suite.health.scheduler.late' : 'suite.health.scheduler.on_time',
            ['count' => count($tasks), 'late' => $late, 'unseen' => $unseen],
            ['tasks' => count($tasks), 'late' => $late, 'unseen' => $unseen],
        );
    }

    /** @return list<HealthCheck> */
    private function queues(): array
    {
        $async = $this->queueInspector->queue(QueueInspector::ASYNC);
        $failed = $this->queueInspector->queue(QueueInspector::FAILED);

        $age = null !== $async['oldestAt'] ? max(0, time() - new DateTimeImmutable($async['oldestAt'])->getTimestamp()) : null;

        return [
            new HealthCheck(
                'queue_async',
                null !== $age && $age > self::QUEUE_STALE_AFTER ? HealthStatusEnum::Warning : (null === $async['count'] ? HealthStatusEnum::Unknown : HealthStatusEnum::Ok),
                'suite.health.queue_async.label',
                null === $async['count'] ? 'suite.health.queue_async.uncounted' : 'suite.health.queue_async.waiting',
                ['count' => $async['count'] ?? 0, 'seconds' => $age ?? 0],
                ['count' => $async['count'], 'oldestAt' => $async['oldestAt']],
            ),
            new HealthCheck(
                'queue_failed',
                ($failed['count'] ?? 0) > 0 ? HealthStatusEnum::Danger : (null === $failed['count'] ? HealthStatusEnum::Unknown : HealthStatusEnum::Ok),
                'suite.health.queue_failed.label',
                null === $failed['count'] ? 'suite.health.queue_failed.uncounted' : 'suite.health.queue_failed.count',
                ['count' => $failed['count'] ?? 0],
                ['count' => $failed['count'], 'oldestAt' => $failed['oldestAt']],
            ),
        ];
    }
}
