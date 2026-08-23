<?php

declare(strict_types=1);

namespace Aurora\Core\EventSubscriber;

use Aurora\Module\General\GeneralContext;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles the "Général" section route gating. Currently only the
 * dashboard is gated: when the current user cannot access it - either
 * because the toggle is masked (globally or per-user) OR because they
 * lack the `general.dashboard.view` privilege - they are redirected to
 * `backend_general_profile` instead of seeing a 404 or an Access Denied page.
 *
 * Why redirect (vs the standard Access-Denied page like every other
 * gated controller): the Dashboard is the post-login landing route.
 * Greeting an authenticated user with an error feels wrong; sending
 * them to their profile is a softer, predictable fallback that always
 * works (the profile route is open to ROLE_USER).
 */
final readonly class GeneralRouteGateSubscriber implements EventSubscriberInterface
{
    private const string DASHBOARD_PRIVILEGE = 'general.dashboard.view';

    public function __construct(
        private GeneralContext $generalContext,
        private Security $security,
        private UrlGeneratorInterface $urlGenerator,
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
        if ('backend_dashboard' !== $route) {
            return;
        }

        // Two reasons for the user to lose dashboard access - both fall
        // back to the profile page rather than surfacing an error.
        $canAccess = $this->generalContext->isDashboardEnabled()
            && $this->security->isGranted(self::DASHBOARD_PRIVILEGE);

        if ($canAccess) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('backend_general_profile')));
    }
}
