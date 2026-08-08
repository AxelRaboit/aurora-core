<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\EventListener;

use Aurora\Module\Platform\Auth\Service\FrontendAuthGate;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Makes the front auth routes vanish when the install has no front-end accounts.
 *
 * Dropping the menu entries is not enough on its own: the routes stay in the
 * router, so the login form remains reachable — and indexable — by anyone who
 * types the URL. 404 rather than 403 matches how the same controller already
 * answers for an inactive locale; on a site without accounts the page
 * genuinely does not exist.
 *
 * Priority 10 puts this after the router (32), which is what populates
 * `_route`, but ahead of the firewall (8): `frontend_login_check` is consumed
 * by LoginAuthenticator, so a listener sitting at the default 0 would let a
 * POST authenticate someone before the 404 ever ran.
 *
 * Not folded into the `*RouteGateSubscriber` family: those match a route
 * prefix against a module toggle, and the front auth routes share no prefix
 * that does not also swallow `frontend_root`. They also run at priority 0,
 * which is too late for the reason above. The explicit route list here
 * mirrors {@see RedirectAuthenticatedFromGuestRoutesListener} instead.
 */
#[AsEventListener(priority: 10)]
final readonly class FrontendAuthDisabledListener
{
    /**
     * `frontend_logout` is deliberately absent. Turning the parameter off does
     * not end the sessions already open, and 404-ing their only way out would
     * strand those visitors until the cookie expires. On a site with no
     * accounts it is a POST route nobody can reach anyway.
     */
    private const array GATED_ROUTES = [
        'frontend_login',
        'frontend_login_check',
        'frontend_register',
        'frontend_register_confirm',
        'frontend_resend_verification',
        'frontend_verify_email',
        'frontend_forgot_password',
        'frontend_reset_password',
        'frontend_account',
    ];

    public function __construct(
        private FrontendAuthGate $gate,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !in_array($route, self::GATED_ROUTES, true)) {
            return;
        }

        if ($this->gate->isEnabled()) {
            return;
        }

        throw new NotFoundHttpException(sprintf('Front-end accounts are disabled; route "%s" is unavailable.', $route));
    }
}
