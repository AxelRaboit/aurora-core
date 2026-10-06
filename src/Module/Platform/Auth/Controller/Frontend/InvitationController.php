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
 * Accepting an invitation on the public site.
 *
 * The mirror of {@see \Aurora\Module\Platform\Auth\Controller\Suite\InvitationController},
 * and a separate controller for the same reason the two firewalls are separate:
 * the two populations do not mix. What changes here is the firewall the person
 * is logged into, the page they land on, and the type of account accepted.
 *
 * The token and its lifecycle, though, are shared: it is the same
 * `findValidInvitation` / `consumeInvitation` as the suite, so only one
 * expiry and hashing mechanism to maintain.
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

        // Already logged in: the link has nothing left to give, and making them
        // set a password again would be a roundabout way to change it without
        // knowing the old one.
        if ($this->getUser() instanceof UserInterface) {
            return $this->redirectToRoute('frontend_account', ['locale' => $locale]);
        }

        $user = $this->userManager->findValidInvitation($selector, $token);

        /*
         * A suite account token is not accepted here, and vice versa.
         *
         * `findValidInvitation` does not filter on the type - it does not have
         * to, the token mechanism is shared. So it is up to each route to
         * refuse the population that is not its own. Without this guard, a
         * suite invitee following this address would be logged into the public
         * firewall, where their account does not exist: their session would
         * drop on the next refresh, with nothing to explain it to them.
         *
         * The refusal is indistinguishable from an expired token, on purpose:
         * the page has no business revealing that an account exists elsewhere.
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

            // The public firewall, named explicitly: the route is not under
            // ^/suite, so it would be inferred correctly, but a silent
            // inference on a programmatic login is something one rereads three
            // times without being sure.
            $this->security->login($user, firewallName: 'main');

            return new RedirectResponse($this->generateUrl('frontend_account', ['locale' => $locale]));
        }

        return $this->render(
            $this->themeResolver->resolve('auth/invitation'),
            $this->viewBuilder->invitationView($locale, $selector, $token, false, [], $user->getName()),
        );
    }
}
