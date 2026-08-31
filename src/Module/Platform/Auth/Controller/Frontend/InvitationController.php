<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Controller\Frontend;

use Aurora\Core\Enum\HttpMethodEnum;
use Aurora\Core\Validation\Service\PayloadValidator;
use Aurora\Module\Configuration\Theme\Service\ThemeResolver;
use Aurora\Module\Platform\Auth\View\Frontend\AuthViewBuilder;
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
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Accepter une invitation sur le site public.
 *
 * Le miroir de {@see \Aurora\Module\Platform\Auth\Controller\Backend\InvitationController},
 * et un contrôleur séparé pour la même raison que les deux firewalls le sont :
 * les deux populations ne se mélangent pas. Ce qui change ici est le firewall où
 * la personne est connectée, la page où elle atterrit, et le type de compte
 * accepté.
 *
 * Le jeton et son cycle de vie, eux, sont partagés : c'est le même
 * `findValidInvitation` / `consumeInvitation` que le backend, donc une seule
 * mécanique d'expiration et de hachage à maintenir.
 */
final class InvitationController extends AbstractController
{
    public function __construct(
        private readonly UserManagerInterface $userManager,
        private readonly PayloadValidator $payloadValidator,
        private readonly Security $security,
        private readonly ThemeResolver $themeResolver,
        private readonly AuthViewBuilder $viewBuilder,
    ) {}

    #[Route('/{locale}/invitation/{selector}/{token}', name: 'frontend_invitation_accept', requirements: ['locale' => '[a-z]{2}'], methods: [HttpMethodEnum::Get->value, HttpMethodEnum::Post->value], priority: 8)]
    public function accept(Request $request, string $locale, string $selector, string $token): Response
    {
        $request->setLocale($locale);

        // Déjà connecté : le lien n'a plus rien à donner, et lui faire reposer un
        // mot de passe serait un moyen détourné d'en changer sans connaître
        // l'ancien.
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('frontend_account', ['locale' => $locale]);
        }

        $user = $this->userManager->findValidInvitation($selector, $token);

        /*
         * Un jeton de compte backend n'est pas accepté ici, et réciproquement.
         *
         * `findValidInvitation` ne filtre pas le type - il n'a pas à le faire,
         * la mécanique du jeton est commune. C'est donc à chaque route de
         * refuser la population qui n'est pas la sienne. Sans ce garde, un
         * invité backend qui suivrait cette adresse serait connecté sur le
         * firewall public, où son compte n'existe pas : sa session sauterait au
         * rafraîchissement suivant, sans rien qui le lui explique.
         *
         * Le refus est indistinguable d'un jeton expiré, délibérément : la page
         * n'a pas à révéler qu'un compte existe ailleurs.
         */
        if (!$user instanceof User || UserTypeEnum::Frontend !== $user->getType()) {
            return $this->render(
                $this->themeResolver->resolve('auth/invitation'),
                $this->viewBuilder->invitationView($locale, $selector, $token, true),
            );
        }

        if ($request->isMethod(HttpMethodEnum::Post->value)) {
            $input = new UserSetPasswordInput(
                password: (string) $request->request->get('password', ''),
                passwordConfirm: (string) $request->request->get('password_confirmation', ''),
            );

            $errors = $this->payloadValidator->errors($input);
            if ([] !== $errors) {
                return $this->render(
                    $this->themeResolver->resolve('auth/invitation'),
                    $this->viewBuilder->invitationView($locale, $selector, $token, false, $errors, $user->getName()),
                );
            }

            $this->userManager->consumeInvitation($user, $input->password);

            // Le firewall public, nommé explicitement : la route n'est pas sous
            // ^/backend, donc il serait déduit correctement, mais un déduit
            // silencieux sur une connexion programmatique est ce qu'on relit
            // trois fois sans en être sûr.
            $this->security->login($user, firewallName: 'main');

            return new RedirectResponse($this->generateUrl('frontend_account', ['locale' => $locale]));
        }

        return $this->render(
            $this->themeResolver->resolve('auth/invitation'),
            $this->viewBuilder->invitationView($locale, $selector, $token, false, [], $user->getName()),
        );
    }
}
