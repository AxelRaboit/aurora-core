<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Aurora\Core\Module\Service\ModuleAccessChecker;

/**
 * Toggle façade for the "{{MODULE_LABEL}}" module. Every consumer (route
 * gate, nav builder, controllers) routes through this service so the
 * global + per-user + cascade resolution is applied consistently.
 */
final readonly class {{MODULE}}Context
{
    public const string SUITE_KEY = 'app_{{MODULE_ID}}_suite';

    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isSuiteEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(self::SUITE_KEY);
    }
}
