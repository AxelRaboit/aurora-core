<?php

declare(strict_types=1);

namespace {{NAMESPACE}};

use Aurora\Core\Module\Service\ModuleAccessChecker;
use {{NAMESPACE}}\Setting\{{MODULE}}ModuleParameterEnum;

/**
 * Toggle façade for the "{{MODULE_LABEL}}" module. Every consumer (route
 * gate, nav builder, controllers) routes through this service so the
 * global + per-user + cascade resolution is applied consistently.
 */
final readonly class {{MODULE}}Context
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isBackendEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled({{MODULE}}ModuleParameterEnum::Backend->value);
    }
}
