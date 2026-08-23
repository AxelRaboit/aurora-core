<?php

declare(strict_types=1);

namespace Aurora\Core\Scheduler;

use Aurora\Core\Scheduler\Message\CleanTempFilesMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The 'main' schedule. Only core's own recurring jobs are declared here; every
 * module contributes its cron messages through a
 * {@see RecurringMessageProviderInterface}, aggregated below - so aurora-core
 * has no dependency on module message classes.
 */
#[AsSchedule('main')]
final readonly class MainSchedule implements ScheduleProviderInterface
{
    /**
     * @param iterable<RecurringMessageProviderInterface> $providers
     */
    public function __construct(
        private CacheInterface $cache,
        private iterable $providers = [],
    ) {}

    public function getSchedule(): Schedule
    {
        $schedule = new Schedule()
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)
            ->add(
                RecurringMessage::cron('0 * * * *', new CleanTempFilesMessage()),
            );

        foreach ($this->providers as $provider) {
            foreach ($provider->getRecurringMessages() as $message) {
                $schedule->add($message);
            }
        }

        return $schedule;
    }
}
