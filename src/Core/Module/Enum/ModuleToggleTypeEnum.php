<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Enum;

/**
 * Classification of a {@see ModuleParameterEnum} toggle by what it
 * controls: a suite (admin) module/sub-module or a public front (the
 * site served to the end user).
 *
 * Surfaced in practice through the `/dev/dashboard/modules` modal (a
 * "Suite" or "Frontend" badge next to each toggle, to tell the two axes
 * apart at a glance).
 *
 * Classification convention (see {@see self::fromKey()}): keys whose
 * suffix is `_frontend` are fronts, everything else is suite. This rule
 * reflects the current naming convention `suite_<module>_frontend`
 * introduced by the migration
 * `Version20260511180000`.
 */
enum ModuleToggleTypeEnum: string
{
    case Suite = 'suite';
    case Frontend = 'frontend';

    public static function fromKey(string $settingKey): self
    {
        return str_ends_with($settingKey, '_frontend') ? self::Frontend : self::Suite;
    }
}
