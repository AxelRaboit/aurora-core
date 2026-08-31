<?php

declare(strict_types=1);

namespace Aurora\Module\Platform\User\Enum;

enum UserTypeEnum: string
{
    case Backend = 'backend';
    case Frontend = 'frontend';

    public function getLabelKey(): string
    {
        return 'backend.users.type.'.$this->value;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
