<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Enum;

enum UserStatusEnum: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Disabled = 'disabled';
    case PendingVerification = 'pending_verification';

    public function getLabelKey(): string
    {
        return 'suite.users.status.'.$this->value;
    }
}
