<?php

declare(strict_types=1);

namespace Aurora\Core\EventSubscriber;

use Aurora\Module\Platform\PlatformContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * 404s the Platform admin routes (Users) when their toggle is OFF -
 * globally or for the current user. Media routes are gated by
 * {@see MediaRouteGateSubscriber} (Jalon 4.5), Configuration routes by
 * {@see ConfigurationRouteGateSubscriber} (Jalon 4).
 *
 * The Dashboard (`backend_dashboard`) is intentionally not gated here:
 * it is the post-login landing page and is always accessible.
 */
final readonly class PlatformRouteGateSubscriber implements EventSubscriberInterface
{
    /** Map of route prefix → callable returning bool (false ⇒ 404). */
    private array $gates;

    public function __construct(private PlatformContext $platformContext)
    {
        $this->gates = [
            'backend_platform_users' => $this->platformContext->isUsersEnabled(...),
        ];
    }

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
        if ('' === $route) {
            return;
        }

        foreach ($this->gates as $prefix => $isEnabled) {
            if (str_starts_with($route, $prefix) && !$isEnabled()) {
                throw new NotFoundHttpException();
            }
        }
    }
}
