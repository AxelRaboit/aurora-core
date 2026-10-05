<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\Auth\Dto\RegisterInput;
use Aurora\Module\Platform\Auth\View\RegisterViewBuilder;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;

final class RegisterController extends AbstractController
{
    public function __construct(
        private readonly UserManagerInterface $userManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly SettingRepository $settingRepository,
        private readonly RegisterViewBuilder $viewBuilder,
    ) {}

    #[Route('/suite/platform/register', name: 'suite_platform_register')]
    public function register(Request $request): Response
    {
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('suite_dashboard');
        }

        $registrationEnabled = $this->settingRepository->getBoolean(ApplicationParameterEnum::AdminRegistrationEnabled->value);

        if (!$registrationEnabled || !$request->isMethod(HttpMethodEnum::Post->value)) {
            return $this->render('@Platform/suite/auth/register.html.twig', $this->viewBuilder->registerView($registrationEnabled));
        }

        $input = RegisterInput::fromRequest($request);

        $errors = $this->payloadValidator->errors($input);
        if ([] !== $errors) {
            return $this->render('@Platform/suite/auth/register.html.twig', $this->viewBuilder->registerView(true, $errors, $request->request->all()));
        }

        $this->userManager->register($input->name, $input->email, $input->password);
        $request->getSession()->set('_admin_pending_email', $input->email);

        return $this->redirectToRoute('suite_platform_register_confirm');
    }

    #[Route('/suite/platform/register/confirm', name: 'suite_platform_register_confirm', methods: [HttpMethodEnum::Get->value])]
    public function registerConfirm(Request $request): Response
    {
        $pendingEmail = $request->getSession()->get('_admin_pending_email');
        $resent = $request->query->getBoolean('resent');

        return $this->render('@Platform/suite/auth/register_confirm.html.twig', $this->viewBuilder->confirmView(
            is_string($pendingEmail) ? $pendingEmail : null,
            $resent,
        ));
    }

    #[Route('/suite/platform/resend-verification', name: 'suite_platform_resend_verification', methods: [HttpMethodEnum::Post->value])]
    public function resendVerification(Request $request): Response
    {
        $email = $request->getSession()->get('_admin_pending_email', '');
        if ('' !== $email) {
            $this->userManager->resendVerificationEmail($email);
        }

        return $this->redirectToRoute('suite_platform_register_confirm', ['resent' => 1]);
    }

    #[Route('/suite/platform/verify-email/{token}', name: 'suite_platform_verify_email', methods: [HttpMethodEnum::Get->value])]
    public function verifyEmail(string $token): Response
    {
        $user = $this->userManager->verifyEmail($token);

        return $this->render('@Platform/suite/auth/verify_email.html.twig', $this->viewBuilder->verifyView($user instanceof User));
    }
}
