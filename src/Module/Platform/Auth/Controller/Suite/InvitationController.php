<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Suite;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Platform\Auth\View\InvitationViewBuilder;
use Aurora\Module\Platform\User\Dto\UserSetPasswordInput;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Manager\UserManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly UserManagerInterface $userManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly Security $security,
        private readonly TranslatorInterface $translator,
        private readonly InvitationViewBuilder $viewBuilder,
    ) {}

    #[Route('/suite/platform/invitation/{selector}/{token}', name: 'suite_platform_invitation_accept', methods: [HttpMethodEnum::Get->value, HttpMethodEnum::Post->value])]
    public function accept(Request $request, string $selector, string $token): Response
    {
        $user = $this->userManager->findValidInvitation($selector, $token);

        /*
         * A frontend account token is not accepted here.
         *
         * `findValidInvitation` does not filter on the type, and that is
         * expected: the token mechanism is shared by both populations. The
         * filtering therefore belongs to the route. Without this guard, a
         * frontend invitee following this address would be logged into the
         * admin firewall, where their account does not exist -
         * `admin_user_provider` only resolves suite accounts, so the session
         * would drop on the next refresh, after a stop on the dashboard.
         *
         * The refusal borrows the same message as an expired token: this page
         * has no business revealing that an account exists elsewhere.
         */
        if (!$user instanceof User || UserTypeEnum::Suite !== $user->getType()) {
            $this->addFlash('error', $this->translator->trans('suite.auth.invitation.expired'));

            return $this->redirectToRoute('suite_platform_login');
        }

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $input = new UserSetPasswordInput(
                password: (string) $request->request->get('password', ''),
                passwordConfirm: (string) $request->request->get('password_confirm', ''),
            );

            $errors = $this->payloadValidator->errors($input);
            if ([] !== $errors) {
                return $this->render('@Platform/suite/auth/invitation_accept.html.twig', $this->viewBuilder->acceptView($user, $selector, $token, $errors));
            }

            $this->userManager->consumeInvitation($user, $input->password);

            $this->security->login($user);

            $this->addFlash('success', $this->translator->trans('suite.auth.invitation.success'));

            return new RedirectResponse($this->generateUrl('suite_dashboard'));
        }

        return $this->render('@Platform/suite/auth/invitation_accept.html.twig', $this->viewBuilder->acceptView($user, $selector, $token));
    }
}
