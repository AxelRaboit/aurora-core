<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\EventListener;

use Symfony\Bundle\SecurityBundle\Security\FirewallMap;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * An unknown backend URL is a 404 for someone signed in, and the login page for
 * everyone else — so the backend does not tell a stranger which of its routes
 * exist.
 *
 * `access_control` cannot express this: `RouterListener` throws
 * `NotFoundHttpException` at priority 32, and the firewall runs at 8, so an
 * unmatched path never reaches security at all.
 *
 * Which is also why asking the token storage is not enough. For the case this
 * listener exists to handle, the firewall has not run, so the storage is empty
 * — for a signed-in admin exactly as for an anonymous visitor. Read on its own,
 * it sent *everyone* to the login page, and the distinction the class is named
 * after never happened. It appeared to work only for 404s raised later, from a
 * controller or an argument resolver, where the firewall had already run.
 *
 * So when the storage is empty we look where the firewall would have looked:
 * the session, under the key its firewall context uses.
 */
#[AsEventListener]
final readonly class RedirectUnauthenticatedOnNotFoundListener
{
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private UrlGeneratorInterface $urlGenerator,
        #[Autowire(service: 'security.firewall.map')]
        private FirewallMap $firewallMap,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        if (!$event->getThrowable() instanceof NotFoundHttpException) {
            return;
        }

        $request = $event->getRequest();

        if (!$this->isProtectedPath($request->getPathInfo()) || $this->isSignedIn($request)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->urlGenerator->generate('backend_platform_login'),
        ));
    }

    private function isSignedIn(Request $request): bool
    {
        if ($this->tokenStorage->getToken()?->getUser() instanceof UserInterface) {
            return true;
        }

        return null !== $this->sessionToken($request);
    }

    /**
     * The serialised token the firewall would have restored, or null.
     *
     * The context comes from the firewall configuration rather than a constant:
     * renaming `context:` in `security.yaml` would otherwise silently send
     * every signed-in admin back to the login page on a mistyped URL, which is
     * the failure this listener already had once.
     */
    private function sessionToken(Request $request): ?string
    {
        $context = $this->firewallMap->getFirewallConfig($request)?->getContext();
        if (null === $context || !$request->hasSession()) {
            return null;
        }

        $session = $request->getSession();

        // Reading would start a session, and hand a cookie to a stranger who
        // only mistyped a URL. No cookie, nobody to be signed in as.
        if (!$request->cookies->has($session->getName())) {
            return null;
        }

        $token = $session->get('_security_'.$context);

        return is_string($token) ? $token : null;
    }

    private function isProtectedPath(string $path): bool
    {
        $adminPrefix = $this->urlGenerator->generate('backend_dashboard');
        $devPrefix = $this->urlGenerator->generate('dev_dashboard');

        return str_starts_with($path, $adminPrefix) || str_starts_with($path, $devPrefix);
    }
}
