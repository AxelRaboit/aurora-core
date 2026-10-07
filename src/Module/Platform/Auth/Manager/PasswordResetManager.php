<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Manager;

use Aurora\Core\Sequence\SequenceGenerator;
use Aurora\Core\Sequence\SequencePrefixEnum;
use Aurora\Module\Configuration\Setting\Enum\ApplicationParameterEnum;
use Aurora\Module\Configuration\Setting\Repository\SettingRepository;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequestInterface;
use Aurora\Module\Platform\Auth\Repository\ResetPasswordRequestRepository;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use Aurora\Module\Platform\User\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

#[AsAlias(PasswordResetManagerInterface::class)]
class PasswordResetManager implements PasswordResetManagerInterface
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
        protected readonly UserRepository $userRepository,
        protected readonly ResetPasswordRequestRepository $resetRequestRepository,
        protected readonly UserPasswordHasherInterface $passwordHasher,
        protected readonly MailerInterface $mailer,
        protected readonly SettingRepository $settingRepository,
        protected readonly UrlGeneratorInterface $urlGenerator,
        protected readonly Environment $twig,
        protected readonly TranslatorInterface $translator,
        protected readonly string $mailerFrom,
        protected readonly SequenceGenerator $sequenceGenerator,
    ) {}

    /**
     * Admin "forgot password" entry point: looks up the admin user by email
     * and sends a reset email if found (silent otherwise).
     */
    public function sendResetLink(string $email): void
    {
        $user = $this->userRepository->findOneBy(['email' => $email, 'type' => UserTypeEnum::Suite]);

        if (!$user instanceof User) {
            return;
        }

        ['selector' => $selector, 'plainToken' => $plainToken, 'expiresAt' => $expiresAt] = $this->createRequestForUser($user);

        $resetUrl = $this->urlGenerator->generate('suite_platform_reset_password', [
            'selector' => $selector,
            'token' => $plainToken,
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $this->sendResetEmail($user, $resetUrl, $expiresAt);
    }

    /**
     * Creates a fresh reset request for the user (replacing any previous one).
     *
     * @return array{selector: string, plainToken: string, expiresAt: DateTimeImmutable}
     */
    public function createRequestForUser(User $user): array
    {
        $this->resetRequestRepository->deleteByUser($user);

        $selector = bin2hex(random_bytes(10));
        $plainToken = bin2hex(random_bytes(32));
        $hashedToken = hash('sha256', $plainToken);
        $expiresAt = new DateTimeImmutable('+1 hour');

        $resetRequest = $this->createResetPasswordRequest($user, $selector, $hashedToken, $expiresAt);
        $prefix = $this->settingRepository->get(ApplicationParameterEnum::CoreResetPasswordPrefix->value, SequencePrefixEnum::ResetPasswordRequest->value) ?? SequencePrefixEnum::ResetPasswordRequest->value;
        $resetRequest->setReference($this->sequenceGenerator->next($prefix));
        $this->entityManager->persist($resetRequest);
        $this->entityManager->flush();

        return ['selector' => $selector, 'plainToken' => $plainToken, 'expiresAt' => $expiresAt];
    }

    public function sendResetEmail(User $user, string $resetUrl, ?DateTimeImmutable $expiresAt = null): void
    {
        $siteName = $this->settingRepository->getOrDefault(ApplicationParameterEnum::SiteName);

        $body = $this->twig->render('@Shared/email/reset_password.html.twig', [
            'userName' => $user->getName(),
            'resetUrl' => $resetUrl,
            'expiresAt' => $expiresAt,
            'siteName' => $siteName,
        ]);

        $subject = $this->translator->trans('shared.mail.reset_password.heading');

        $this->mailer->send(new Email()
            ->from($this->mailerFrom)
            ->to($user->getEmail())
            ->subject(sprintf('[%s] %s', $siteName, $subject))
            ->html($body));
    }

    public function validateToken(string $selector, string $token, ?UserTypeEnum $expectedType = UserTypeEnum::Suite): ?ResetPasswordRequest
    {
        $resetRequest = $this->resetRequestRepository->findBySelector($selector);

        if (!$resetRequest instanceof ResetPasswordRequest || $resetRequest->isExpired()) {
            return null;
        }

        if ($expectedType instanceof UserTypeEnum && $resetRequest->getUser()->getType() !== $expectedType) {
            return null;
        }

        if (!hash_equals($resetRequest->getHashedToken(), hash('sha256', $token))) {
            return null;
        }

        return $resetRequest;
    }

    public function resetPassword(ResetPasswordRequest $resetRequest, string $newPassword): void
    {
        $user = $resetRequest->getUser();
        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));

        $this->entityManager->remove($resetRequest);
        $this->entityManager->flush();
    }

    protected function createResetPasswordRequest(User $user, string $selector, string $hashedToken, DateTimeImmutable $expiresAt): ResetPasswordRequestInterface
    {
        return new ResetPasswordRequest($user, $selector, $hashedToken, $expiresAt);
    }
}
