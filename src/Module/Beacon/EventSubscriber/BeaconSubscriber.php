<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\EventSubscriber;

use Aurora\Module\Beacon\Service\BeaconSender;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function str_starts_with;

/**
 * Fires the daily beacon check-in once per day, on kernel.terminate so it runs
 * after the response has been sent to the visitor and never adds latency.
 *
 * The once-a-day gate is a cache item with a ~25h TTL: the first request of the
 * day misses, sends, and stores the marker; every later request hits it and
 * does nothing. The sender itself swallows its own failures, so a dead receiver
 * never surfaces here.
 */
final readonly class BeaconSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private BeaconSender $sender,
        private CacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $request = $event->getRequest();

        // Skip framework-internal paths (profiler, the beacon endpoint itself,
        // error fragments): only real page views should count as a heartbeat.
        if (str_starts_with($request->getPathInfo(), '/_')) {
            return;
        }

        $host = $request->getHost();

        $this->cache->get('beacon.checkin.'.date('Y-m-d'), function (ItemInterface $item) use ($host): bool {
            $item->expiresAfter(90000);
            $this->sender->send($host);

            return true;
        });
    }
}
