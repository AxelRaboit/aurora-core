<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Dto;

use Aurora\Core\Support\Str;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(UserInviteInputFactoryInterface::class)]
class UserInviteInputFactory implements UserInviteInputFactoryInterface
{
    public function fromArray(array $data): UserInviteInputInterface
    {
        return new UserInviteInput(
            name: Str::trimFromArray($data, 'name'),
            email: Str::trimFromArray($data, 'email'),
            role: Str::trimFromArray($data, 'role'),
            message: Str::trimOrNullFromArray($data, 'message'),
            disabled: (bool) ($data['disabled'] ?? false),
            type: Str::trimOrNullFromArray($data, 'type') ?? 'backend',
        );
    }
}
