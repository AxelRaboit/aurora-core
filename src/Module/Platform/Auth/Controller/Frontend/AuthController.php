<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Frontend\Service\Context;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Platform\Auth\Dto\Frontend\RegisterInput;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\Auth\Service\FrontendAuthGate;
use Aurora\Module\Platform\Auth\View\Frontend\AuthViewBuilder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserRoleEnum;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Manager\Frontend\UserManager;
use Aurora\Module\Platform\User\Repository\UserRepository;
use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Contracts\Translation\TranslatorInterface;

class AuthController extends AbstractController
{
    public function __construct(
        private readonly UserManager $frontUserManager,
        private readonly UserRepository $userRepository,
        private readonly FrontendAuthGate $authGate,
        private readonly TranslatorInterface $translator,
        private readonly PayloadValidator $payloadValidator,
        private readonly Context $context,
        private readonly ThemeResolver $themeResolver,
        private readonly AuthViewBuilder $viewBuilder,
    ) {}

    #[Route('/{locale}/login', name: 'frontend_login', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    public function login(string $locale, Request $request, AuthenticationUtils $authUtils): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        // Already authenticated front user → redirect to account
        if ($this->getUser() instanceof UserInterface && method_exists($this->getUser(), 'isFrontUser') && $this->getUser()->isFrontUser()) {
            return $this->redirectToRoute('frontend_account', ['locale' => $locale]);
        }

        return $this->render($this->themeResolver->resolve('auth/login'), $this->viewBuilder->loginView(
            $locale,
            $authUtils->getLastUsername(),
            $authUtils->getLastAuthenticationError(),
        ));
    }

    #[Route('/front-login-check', name: 'frontend_login_check', methods: [HttpMethodEnum::Post->value])]
    public function loginCheck(): never
    {
        throw new LogicException('Handled by FrontLoginAuthenticator.');
    }

    #[Route('/{locale}/register', name: 'frontend_register', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    public function register(string $locale, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('frontend_root');
        }

        $registrationEnabled = $this->authGate->isRegistrationEnabled();

        if (!$registrationEnabled || !$request->isMethod(HttpMethodEnum::Post->value)) {
            return $this->render($this->themeResolver->resolve('auth/register/index'), $this->viewBuilder->registerView(
                $locale,
                $registrationEnabled,
                [],
                [],
                false,
            ));
        }

        $input = RegisterInput::fromArray($request->request->all(), $locale);
        $errors = $this->payloadValidator->errors($input);

        if ([] === $errors && $this->userRepository->findOneBy(['email' => $input->email, 'type' => UserTypeEnum::Frontend])) {
            $errors['email'] = 'frontend.errors.email_taken';
        }

        if ([] === $errors) {
            $this->frontUserManager->register($input);
            $request->getSession()->set('_front_pending_email', $input->email);

            return $this->redirectToRoute('frontend_register_confirm', ['locale' => $locale]);
        }

        return $this->render($this->themeResolver->resolve('auth/register/index'), $this->viewBuilder->registerView(
            $locale,
            true,
            $errors,
            [
                'name' => $request->request->get('name', ''),
                'email' => $request->request->get('email', ''),
            ],
            true,
        ));
    }

    #[Route('/{locale}/register/confirm', name: 'frontend_register_confirm', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    public function registerConfirm(string $locale, Request $request): Response
    {
        $this->assertActiveLocale($locale);

        $pendingEmail = $request->getSession()->get('_front_pending_email');
        $resent = $request->query->getBoolean('resent');

        return $this->render($this->themeResolver->resolve('auth/register/confirm'), $this->viewBuilder->registerConfirmView(
            $locale,
            is_string($pendingEmail) ? $pendingEmail : null,
            $resent,
        ));
    }

    #[Route('/{locale}/resend-verification', name: 'frontend_resend_verification', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 8)]
    public function resendVerification(string $locale, Request $request): Response
    {
        $this->assertActiveLocale($locale);

        $email = $request->getSession()->get('_front_pending_email', '');
        if ('' !== $email) {
            $this->frontUserManager->resendVerificationEmail($email, $locale);
        }

        return $this->redirectToRoute('frontend_register_confirm', ['locale' => $locale, 'resent' => 1]);
    }

    #[Route('/{locale}/verify-email/{token}', name: 'frontend_verify_email', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    public function verifyEmail(string $locale, string $token): Response
    {
        $this->assertActiveLocale($locale);

        $user = $this->frontUserManager->verifyEmail($token);

        return $this->render($this->themeResolver->resolve('auth/verify_email'), $this->viewBuilder->verifyEmailView(
            $locale,
            $user instanceof User,
        ));
    }

    #[Route('/{locale}/forgot-password', name: 'frontend_forgot_password', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    public function forgotPassword(string $locale, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('frontend_account', ['locale' => $locale]);
        }

        $sent = false;

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $email = mb_trim($request->request->getString('email'));
            $this->frontUserManager->sendPasswordResetEmail($email, $locale);
            $sent = true;
        }

        return $this->render($this->themeResolver->resolve('auth/password/forgot'), $this->viewBuilder->forgotPasswordView($locale, $sent));
    }

    #[Route('/{locale}/reset-password/{selector}/{token}', name: 'frontend_reset_password', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    public function resetPassword(string $locale, string $selector, string $token, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('frontend_account', ['locale' => $locale]);
        }

        $resetRequest = $this->frontUserManager->validateResetToken($selector, $token);

        if (!$resetRequest instanceof ResetPasswordRequest) {
            return $this->render($this->themeResolver->resolve('auth/password/reset'), $this->viewBuilder->resetPasswordView(
                $locale,
                $selector,
                $token,
                true,
                [],
            ));
        }

        $errors = [];

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $password = $request->request->getString('password');
            $confirm = $request->request->getString('password_confirmation');

            if ('' === $password || mb_strlen($password) < 8) {
                $errors['password'] = $this->translator->trans('frontend.errors.password_too_short');
            } elseif ($password !== $confirm) {
                $errors['password_confirmation'] = $this->translator->trans('frontend.errors.passwords_mismatch');
            }

            if ([] === $errors) {
                $this->frontUserManager->resetPassword($resetRequest, $password);

                return $this->redirectToRoute('frontend_login', ['locale' => $locale, 'reset' => 1]);
            }
        }

        return $this->render($this->themeResolver->resolve('auth/password/reset'), $this->viewBuilder->resetPasswordView(
            $locale,
            $selector,
            $token,
            false,
            $errors,
        ));
    }

    #[Route('/{locale}/account', name: 'frontend_account', requirements: ['locale' => '[a-z]{2}'], priority: 8)]
    #[IsGranted(UserRoleEnum::User->value)]
    public function account(string $locale, Request $request): Response
    {
        $this->assertActiveLocale($locale);
        $request->setLocale($locale);

        return $this->render($this->themeResolver->resolve('auth/account'), $this->viewBuilder->accountView($locale, $this->getUser()));
    }

    #[Route('/{locale}/account/logout', name: 'frontend_logout', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Post->value], priority: 8)]
    public function logout(string $locale, Security $security): Response
    {
        $security->logout(validateCsrfToken: false);

        return $this->redirectToRoute('frontend_root');
    }

    private function assertActiveLocale(string $locale): void
    {
        if (!$this->context->isLocaleActive($locale)) {
            throw $this->createNotFoundException(sprintf('LocaleEnum "%s" is not active.', $locale));
        }
    }
}
