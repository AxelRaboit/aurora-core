<?php

declare(strict_types=1);

namespace Aurora\Module\Studio\CustomerSpace\Enum;

/**
 * Which spaces a cross-space screen looks at: the reader's own, or every one
 * they may see.
 *
 * Only a reader who sees every space has a choice to make. Everybody else
 * sees their own spaces either way.
 */
enum SpaceScopeEnum: string
{
    case Mine = 'mine';

    case All = 'all';

    /** Mine unless the request says otherwise: the reader's own work first. */
    public static function fromRequest(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::Mine;
    }
}
