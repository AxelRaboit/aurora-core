<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Platform\Auth\Dto\ResetPasswordInput;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\Auth\Manager\PasswordResetManagerInterface;
use Aurora\Module\Platform\Auth\View\PasswordResetViewBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetManagerInterface $passwordResetManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly TranslatorInterface $translator,
        private readonly PasswordResetViewBuilder $viewBuilder,
    ) {}

    #[Route('/suite/platform/forgot-password', name: 'suite_platform_forgot_password')]
    public function forgot(Request $request): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('suite_dashboard');
        }

        $status = null;

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $email = mb_trim($request->request->get('email', ''));
            $this->passwordResetManager->sendResetLink($email);
            $status = $this->translator->trans('suite.auth.forgot_password.sent');
        }

        return $this->render('@Platform/suite/auth/forgot_password.html.twig', $this->viewBuilder->forgotView($status));
    }

    #[Route('/suite/platform/reset-password/{selector}/{token}', name: 'suite_platform_reset_password')]
    public function reset(string $selector, string $token, Request $request): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('suite_dashboard');
        }

        $resetRequest = $this->passwordResetManager->validateToken($selector, $token);

        if (!$resetRequest instanceof ResetPasswordRequest) {
            $this->addFlash('error', $this->translator->trans('suite.auth.reset_password.invalid_link'));

            return $this->redirectToRoute('suite_platform_forgot_password');
        }

        $errors = [];

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $input = ResetPasswordInput::fromRequest($request);

            $errors = $this->payloadValidator->errors($input);
            if ([] === $errors) {
                $this->passwordResetManager->resetPassword($resetRequest, $input->password);
                $this->addFlash('success', $this->translator->trans('suite.auth.reset_password.success'));

                return $this->redirectToRoute('suite_platform_login');
            }
        }

        return $this->render('@Platform/suite/auth/reset_password.html.twig', $this->viewBuilder->resetView($selector, $token, $errors));
    }
}
