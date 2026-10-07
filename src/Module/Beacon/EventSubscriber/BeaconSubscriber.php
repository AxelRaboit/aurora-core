<?php

declare(strict_types=1);

namespace Aurora\Module\Beacon\EventSubscriber;

use Aurora\Module\Beacon\Service\BeaconSender;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

use function filter_var;
use function in_array;
use function is_string;
use function mb_trim;
use function parse_url;
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
        #[Autowire(env: 'DEFAULT_URI')]
        private string $defaultUri,
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

        $host = $this->siteHost() ?? $request->getHost();

        $this->cache->get('beacon.checkin.'.date('Y-m-d'), function (ItemInterface $item) use ($host): bool {
            $item->expiresAfter(90000);
            $this->sender->send($host);

            return true;
        });
    }

    /**
     * The site's own address, as configured in DEFAULT_URI.
     *
     * The host of the request that happened to come first is not the site's
     * name: a robot that walks IP ranges calls the server by its address, and
     * on 06/10 and 07/10/2026 production announced itself as 72.60.130.238
     * and showed up as a lead. The configured address does not depend on who
     * knocked. Left at its `localhost` default, or set to a bare IP, it says
     * nothing, and the request's host is still the best clue to where a copy
     * runs.
     */
    private function siteHost(): ?string
    {
        $host = parse_url($this->defaultUri, PHP_URL_HOST);
        if (!is_string($host) || '' === $host || in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return null;
        }

        return false === filter_var(mb_trim($host, '[]'), FILTER_VALIDATE_IP) ? $host : null;
    }
}
