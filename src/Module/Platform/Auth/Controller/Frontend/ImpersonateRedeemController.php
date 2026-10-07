<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Frontend;

use Aurora\Core\Frontend\Service\Router;
use Aurora\Module\Platform\Auth\Service\ImpersonationTokenService;
use Aurora\Module\Platform\User\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

#[Route('/{locale}/impersonate/{token}', name: 'frontend_impersonate_redeem', requirements: ['locale' => '[a-z]{2}'], priority: 10)]
final class ImpersonateRedeemController extends AbstractController
{
    public function __construct(
        private readonly ImpersonationTokenService $tokenService,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly Router $router,
    ) {}

    public function __invoke(string $locale, string $token, Request $request): RedirectResponse
    {
        $user = $this->tokenService->validate($token);

        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('Invalid or expired impersonation token.');
        }

        $authenticationToken = new PostAuthenticationToken($user, 'main', $user->getRoles());
        $this->tokenStorage->setToken($authenticationToken);
        $request->getSession()->set('_security_front', serialize($authenticationToken));

        $this->dispatcher->dispatch(new InteractiveLoginEvent($request, $authenticationToken));

        $front = $this->router->getDefault();

        return $this->redirectToRoute($front->getHomeRoute(), ['locale' => $locale]);
    }
}
