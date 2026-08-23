<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Enum;

/**
 * Stable error codes returned in JSON responses by the settings endpoints.
 * Frontend matches against these values - keep them in sync with the Vue
 * settings views.
 */
enum SettingErrorCodeEnum: string
{
    case CascadeViolation = 'cascade_violation';
}
