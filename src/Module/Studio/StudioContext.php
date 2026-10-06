<?php

declare(strict_types=1);

namespace Aurora\Module\Studio;

use Aurora\Core\Module\Service\ModuleAccessChecker;
use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Toggle façade for the "Studio" module. Every consumer (route
 * gate, nav builder, controllers) routes through this service so the
 * global + per-user + cascade resolution is applied consistently.
 */
final readonly class StudioContext
{
    public function __construct(private ModuleAccessChecker $moduleAccessChecker) {}

    public function isSuiteEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::StudioSuite);
    }

    public function areCustomersEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::StudioCustomers);
    }

    public function areContractsEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::StudioContracts);
    }

    /** Studio's deliverables, the ones attached to no space. */
    public function areDeliverablesEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::StudioDeliverables);
    }

    public function areSpacesEnabled(): bool
    {
        return $this->moduleAccessChecker->isEnabled(ModuleParameterEnum::StudioSpaces);
    }
}
