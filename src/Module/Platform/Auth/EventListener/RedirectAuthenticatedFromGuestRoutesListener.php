<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Redirects authenticated users away from guest-only routes (login, register, …)
 * to the dashboard. Prevents showing the login form when the user is already logged in.
 */
#[AsEventListener]
final readonly class RedirectAuthenticatedFromGuestRoutesListener
{
    private const array GUEST_ONLY_ROUTES = [
        'suite_platform_login',
        'suite_platform_register',
        'suite_platform_forgot_password',
        'suite_platform_access_request',
    ];

    private const string AUTHENTICATED_TARGET_ROUTE = 'suite_dashboard';

    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof TokenInterface || !$token->getUser() instanceof UserInterface) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');
        if (!is_string($route) || !in_array($route, self::GUEST_ONLY_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate(self::AUTHENTICATED_TARGET_ROUTE),
        ));
    }
}
