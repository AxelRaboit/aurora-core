<?php

declare(strict_types=1);

namespace Aurora\Core\Frontend\EventSubscriber;

use Aurora\Core\Frontend\Service\Registry;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 404s every front-side route when the owning front is disabled.
 *
 * For each registered {@see \Aurora\Core\Frontend\Contract\FrontendInterface},
 * matches the current request's `_route` against the front's
 * {@see FrontendInterface::getRoutePrefixes()}. If the front's
 * `getModuleSettingKey()` setting is OFF globally, the request is
 * rejected with a 404.
 *
 * The root dispatcher route (`frontend_root`) is intentionally NOT
 * matched here - when no front is available, its controller redirects
 * to the backend instead of throwing. Per-controller IsGranted /
 * route-level guards still apply on top of this gate.
 */
final readonly class FrontendRouteGateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private Registry $registry,
        private SettingRepository $settingRepository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 0]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');
        if ('' === $route || 'frontend_root' === $route) {
            return;
        }

        foreach ($this->registry->all() as $front) {
            $settingKey = $front->getModuleSettingKey();
            if (null === $settingKey) {
                continue;
            }

            foreach ($front->getRoutePrefixes() as $prefix) {
                if (str_starts_with($route, $prefix)) {
                    if (!$this->settingRepository->getBoolean($settingKey, true)) {
                        throw new NotFoundHttpException();
                    }

                    return;
                }
            }
        }
    }
}
