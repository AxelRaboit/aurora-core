<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Backend;

use Aurora\Module\Platform\Auth\View\LoginViewBuilder;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class LoginController extends AbstractController
{
    public function __construct(private readonly LoginViewBuilder $viewBuilder) {}

    #[Route('/backend/platform/login', name: 'backend_platform_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('backend_dashboard');
        }

        return $this->render('@Platform/backend/auth/login.html.twig', $this->viewBuilder->loginView(
            $authenticationUtils->getLastUsername(),
            $authenticationUtils->getLastAuthenticationError(),
        ));
    }

    #[Route('/backend/platform/logout', name: 'backend_platform_logout')]
    public function logout(): never
    {
        throw new LogicException('This method can be blank - it will be intercepted by the logout key on your firewall.');
    }
}
