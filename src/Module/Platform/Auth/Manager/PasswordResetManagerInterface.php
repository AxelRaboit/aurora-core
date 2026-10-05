<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\Auth\Manager;

use Aurora\Module\Platform\Auth\Entity\ResetPasswordRequest;
use Aurora\Module\Platform\User\Entity\User;
use Aurora\Module\Platform\User\Enum\UserTypeEnum;
use DateTimeImmutable;

interface PasswordResetManagerInterface
{
    public function sendResetLink(string $email): void;

    /**
     * @return array{selector: string, plainToken: string, expiresAt: DateTimeImmutable}
     */
    public function createRequestForUser(User $user): array;

    public function sendResetEmail(User $user, string $resetUrl, ?DateTimeImmutable $expiresAt = null): void;

    public function validateToken(string $selector, string $token, ?UserTypeEnum $expectedType = UserTypeEnum::Suite): ?ResetPasswordRequest;

    public function resetPassword(ResetPasswordRequest $resetRequest, string $newPassword): void;
}
