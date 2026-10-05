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
         * Un jeton de compte frontend n'est pas accepté ici.
         *
         * `findValidInvitation` ne filtre pas le type, et c'est normal : la
         * mécanique du jeton est commune aux deux populations. Le filtrage
         * appartient donc à la route. Sans ce garde, un invité frontend suivant
         * cette adresse serait connecté sur le firewall d'administration, où son
         * compte n'existe pas - `admin_user_provider` ne résout que les comptes
         * suite, donc la session sauterait au rafraîchissement suivant, après
         * un passage sur le tableau de bord.
         *
         * Le refus emprunte le même message qu'un jeton expiré : cette page n'a
         * pas à révéler qu'un compte existe ailleurs.
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
