<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Scheduler;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Scheduler\Event\FailureEvent;
use Symfony\Component\Scheduler\Event\PostRunEvent;
use Throwable;

/**
 * When each scheduled task last ran, and whether it failed.
 *
 * The schedule keeps its own state to know what to run next, not what ran:
 * a task that stopped running looked exactly like a task that had nothing to
 * do. Each run now leaves its time in the application cache, keyed by the
 * task's id in the schedule, and a failure leaves its message.
 */
final readonly class ScheduleRunRecorder
{
    private const string PREFIX = 'aurora.health.schedule.';

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private CacheItemPoolInterface $cache,
    ) {}

    #[AsEventListener]
    public function onPostRun(PostRunEvent $event): void
    {
        $this->write($event->getMessageContext()->id, ['at' => time(), 'failed' => false, 'error' => null]);
    }

    #[AsEventListener]
    public function onFailure(FailureEvent $event): void
    {
        $this->write($event->getMessageContext()->id, [
            'at' => time(),
            'failed' => true,
            'error' => mb_substr($event->getError()->getMessage(), 0, 300),
        ]);
    }

    /** @return array{at: DateTimeImmutable, failed: bool, error: string|null}|null */
    public function lastRunOf(string $taskId): ?array
    {
        try {
            $item = $this->cache->getItem(self::PREFIX.$this->safe($taskId));
        } catch (Throwable) {
            return null;
        }

        $value = $item->isHit() ? $item->get() : null;
        if (!is_array($value) || !is_int($value['at'] ?? null)) {
            return null;
        }

        return [
            'at' => new DateTimeImmutable('@'.$value['at']),
            'failed' => true === ($value['failed'] ?? false),
            'error' => is_string($value['error'] ?? null) ? $value['error'] : null,
        ];
    }

    /** @param array<string, mixed> $value */
    private function write(string $taskId, array $value): void
    {
        try {
            $item = $this->cache->getItem(self::PREFIX.$this->safe($taskId));
            $item->set($value);
            $this->cache->save($item);
        } catch (Throwable) {
            // Never the reason a task fails: the block will show it as not
            // seen, which is what it can honestly say.
        }
    }

    /** A cache key admits fewer characters than a schedule id may carry. */
    private function safe(string $taskId): string
    {
        return hash('xxh128', $taskId);
    }
}
