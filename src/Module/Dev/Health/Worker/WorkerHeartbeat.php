<?php

declare(strict_types=1);

namespace Aurora\Module\Dev\Health\Worker;

use DateTimeImmutable;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Throwable;

/**
 * The worker saying it is alive, and the block reading it.
 *
 * **Nothing said whether the worker ran.** Every mail sent later, every
 * scheduled task, every digest goes through it, and the only way to know it
 * had stopped was to notice that nothing arrived. On 10/10/2026 it crashed
 * twice in the morning when PostgreSQL was restarted under it; systemd
 * brought it back, and nobody could have told.
 *
 * Written in the application cache, which the web pages and the worker
 * share. Not on every loop: a worker idles through a loop every second, and
 * a write every half minute is enough to tell « alive » from « silent for
 * two minutes ». The first loop always writes, so a fresh start shows at
 * once.
 */
final class WorkerHeartbeat
{
    public const string CACHE_KEY = 'aurora.health.worker_heartbeat';

    /** How often a running worker writes, in seconds. */
    public const int WRITE_EVERY = 30;

    /** Past this silence, in seconds, the worker is reported as stopped. */
    public const int SILENT_AFTER = 120;

    private ?int $lastWriteAt = null;

    private ?int $startedAt = null;

    public function __construct(
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
    ) {}

    #[AsEventListener]
    public function onStarted(WorkerStartedEvent $event): void
    {
        $this->startedAt = time();
        $this->lastWriteAt = null;
        $this->beat($event->getWorker()->getMetadata()->getTransportNames());
    }

    #[AsEventListener]
    public function onRunning(WorkerRunningEvent $event): void
    {
        if (null !== $this->lastWriteAt && time() - $this->lastWriteAt < self::WRITE_EVERY) {
            return;
        }

        $this->beat($event->getWorker()->getMetadata()->getTransportNames());
    }

    /**
     * The last beat, or null when no worker ever wrote one.
     *
     * @return array{at: DateTimeImmutable, startedAt: DateTimeImmutable|null, transports: list<string>, pid: int|null}|null
     */
    public function last(): ?array
    {
        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
        } catch (Throwable) {
            return null;
        }

        $value = $item->isHit() ? $item->get() : null;
        if (!is_array($value) || !is_int($value['at'] ?? null)) {
            return null;
        }

        return [
            'at' => new DateTimeImmutable('@'.$value['at']),
            'startedAt' => is_int($value['startedAt'] ?? null) ? new DateTimeImmutable('@'.$value['startedAt']) : null,
            'transports' => array_values(array_filter((array) ($value['transports'] ?? []), is_string(...))),
            'pid' => is_int($value['pid'] ?? null) ? $value['pid'] : null,
        ];
    }

    /** @param list<string> $transports */
    private function beat(array $transports): void
    {
        $now = time();

        try {
            $item = $this->cache->getItem(self::CACHE_KEY);
            $item->set([
                'at' => $now,
                'startedAt' => $this->startedAt,
                'transports' => $transports,
                'pid' => getmypid() ?: null,
            ]);
            $this->cache->save($item);
            $this->lastWriteAt = $now;
        } catch (Throwable) {
            // A cache that cannot be written must not stop the worker: the
            // block will say the worker is silent, which is the truth it can
            // tell.
        }
    }
}
