<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Manager;

use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment as TwigEnvironment;

#[AsAlias(InvitationManagerInterface::class)]
class InvitationManager implements InvitationManagerInterface
{
    public function __construct(
        protected readonly MailerInterface $mailer,
        protected readonly TwigEnvironment $twig,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly SettingRepository $settingRepository,
        protected readonly string $mailerFrom,
    ) {}

    public function sendInvitation(User $user, string $plainToken, ?string $customMessage): void
    {
        $selector = $user->getInvitationSelector();
        if (null === $selector) {
            return;
        }

        /**
         * L'adresse d'acceptation dépend de la population invitée.
         *
         * Les deux firewalls sont distincts et chacun n'accepte que son type
         * (cf. les deux UserProvider) : envoyer un invité frontend sur la page
         * d'acceptation du backend l'y connecterait le temps d'une requête, et
         * la session serait invalidée au rafraîchissement suivant, sans que rien
         * ne lui explique pourquoi. Les deux routes refusent d'ailleurs
         * explicitement le mauvais type.
         *
         * Le lien de connexion suit la même logique : renvoyer quelqu'un vers un
         * formulaire de connexion où son compte n'existe pas est pire que ne pas
         * mettre de lien.
         */
        $isFrontend = UserTypeEnum::Frontend === $user->getType();

        $invitationUrl = $this->urlGenerator->generate(
            $isFrontend ? 'frontend_invitation_accept' : 'backend_platform_invitation_accept',
            $isFrontend
                ? ['locale' => $user->getLocale()->value, 'selector' => $selector, 'token' => $plainToken]
                : ['selector' => $selector, 'token' => $plainToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $loginUrl = $isFrontend
            ? $this->urlGenerator->generate('frontend_login', ['locale' => $user->getLocale()->value], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->urlGenerator->generate('backend_platform_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $siteName = $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName);

        $body = $this->twig->render('@Shared/email/invitation.html.twig', [
            'userName' => $user->getName(),
            'customMessage' => $customMessage,
            'invitationUrl' => $invitationUrl,
            'expiresAt' => $user->getInvitationExpiresAt(),
            'loginUrl' => $loginUrl,
            'siteName' => $siteName,
        ]);

        $this->mailer->send(new Email()
            ->from($this->mailerFrom)
            ->to($user->getEmail())
            ->subject(sprintf('Vous avez été invité à rejoindre %s', $siteName))
            ->html($body));
    }
}
