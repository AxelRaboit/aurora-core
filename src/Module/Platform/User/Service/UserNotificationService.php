<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Service;

use Aurora\Core\Mail\Service\MailService;
use Aurora\Module\Platform\User\Entity\User;

final readonly class UserNotificationService
{
    public function __construct(private MailService $mailService) {}

    public function notifyAccountDeleted(string $email, string $name, ?string $locale = null): void
    {
        $this->mailService->send(
            $email,
            'suite.mail.user.subject_account_deleted',
            '@Core/email/user_account_deleted.html.twig',
            ['name' => $name],
            locale: $locale,
        );
    }

    public function notifyRoleChanged(User $user, string $newRole): void
    {
        $this->mailService->send(
            $user->getEmail(),
            'suite.mail.user.subject_role_changed',
            '@Core/email/user_role_changed.html.twig',
            ['user' => $user, 'newRole' => $newRole],
            locale: $user->getLocale()->value,
        );
    }
}
