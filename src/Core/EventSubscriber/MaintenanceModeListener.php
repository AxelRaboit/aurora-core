<?php

declare(strict_types=1);

namespace Aurora\Core\EventSubscriber;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Closes the public site when `maintenance_mode` is on.
 *
 * The parameter shipped with a label promising the site would be "fermé aux
 * visiteurs" and nothing ever read it, so turning it on did exactly nothing.
 *
 * 503 rather than 404: the site is temporarily unavailable, not gone. Search
 * engines treat a 503 with `Retry-After` as "come back later" and keep the
 * pages indexed, where a 404 would start dropping them. The response body is
 * `error503.html.twig`, resolved through the same TwigBundle exception
 * mechanism as the 404 and 500 pages — so a client project overrides it the
 * same way.
 *
 * Priority 12 sits after the locale subscribers (20/18/16/15), so the page
 * renders in the locale the visitor asked for, and ahead of the firewall (8):
 * a closed site has no reason to start a session or authenticate anyone.
 */
#[AsEventListener(priority: 12)]
final readonly class MaintenanceModeListener
{
    /**
     * Prefixes that stay reachable. `/backend` and `/dev` above all — locking
     * the administrator out of the screen that turns maintenance back off
     * would make this setting a one-way door. `/build` matters too: the
     * maintenance page loads its own stylesheet from there.
     */
    private const array OPEN_PREFIXES = [
        '/backend',
        '/dev',
        '/_',       // profiler, wdt, error previews
        '/assets',
        '/build',
    ];

    private const int RETRY_AFTER_SECONDS = 3600;

    public function __construct(
        private SettingRepository $settingRepository,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $path = $event->getRequest()->getPathInfo();

        // Path check first: a backend request should not even ask the
        // database whether the public site is closed.
        foreach (self::OPEN_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return;
            }
        }

        if (!$this->settingRepository->getBoolean(ApplicationParameterEnum::MaintenanceMode->value)) {
            return;
        }

        throw new ServiceUnavailableHttpException(self::RETRY_AFTER_SECONDS);
    }
}
