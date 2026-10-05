<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Manager\Decorator;

use Aurora\Module\Dev\Audit\Service\AuditLogger;
use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\Auth\Manager\PasswordResetManagerInterface;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;

#[AsDecorator(decorates: PasswordResetManagerInterface::class)]
final readonly class AuditPasswordResetManagerDecorator implements PasswordResetManagerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private PasswordResetManagerInterface $inner,
        private AuditLogger $auditLogger,
    ) {}

    public function sendResetLink(string $email): void
    {
        $this->inner->sendResetLink($email);
        $this->auditLogger->log('core', 'password_reset.link_requested', null, null, ['email' => $email]);
    }

    /**
     * @return array{selector: string, plainToken: string, expiresAt: DateTimeImmutable}
     */
    public function createRequestForUser(User $user): array
    {
        return $this->inner->createRequestForUser($user);
    }

    public function sendResetEmail(User $user, string $resetUrl, ?DateTimeImmutable $expiresAt = null): void
    {
        $this->inner->sendResetEmail($user, $resetUrl, $expiresAt);
    }

    public function validateToken(string $selector, string $token, ?UserTypeEnum $expectedType = UserTypeEnum::Suite): ?ResetPasswordRequest
    {
        return $this->inner->validateToken($selector, $token, $expectedType);
    }

    public function resetPassword(ResetPasswordRequest $resetRequest, string $newPassword): void
    {
        $this->inner->resetPassword($resetRequest, $newPassword);
        $this->auditLogger->log('core', 'password_reset.completed', 'User', $resetRequest->getUser()->getId(), [
            'email' => $resetRequest->getUser()->getEmail(),
        ]);
    }
}
