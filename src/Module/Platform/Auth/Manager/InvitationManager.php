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
         * The acceptance address depends on the invited population.
         *
         * The two firewalls are separate and each accepts only its own type
         * (see the two UserProviders): sending a frontend invitee to the
         * suite's acceptance page would log them in there for one request, and
         * the session would be invalidated on the next refresh, with nothing
         * to explain why. Both routes also explicitly refuse the wrong type.
         *
         * The login link follows the same logic: sending someone to a login
         * form where their account does not exist is worse than giving no
         * link.
         */
        $isFrontend = UserTypeEnum::Frontend === $user->getType();

        $invitationUrl = $this->urlGenerator->generate(
            $isFrontend ? 'frontend_invitation_accept' : 'suite_platform_invitation_accept',
            $isFrontend
                ? ['locale' => $user->getLocale()->value, 'selector' => $selector, 'token' => $plainToken]
                : ['selector' => $selector, 'token' => $plainToken],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $loginUrl = $isFrontend
            ? $this->urlGenerator->generate('frontend_login', ['locale' => $user->getLocale()->value], UrlGeneratorInterface::ABSOLUTE_URL)
            : $this->urlGenerator->generate('suite_platform_login', [], UrlGeneratorInterface::ABSOLUTE_URL);

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
