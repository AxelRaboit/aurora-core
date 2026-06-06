<?php

declare(strict_types=1);

namespace {{NAMESPACE}}\Setting;

use Aurora\Module\Configuration\Setting\Provider\ApplicationParameterProviderInterface;

/**
 * Registers the {{MODULE}} module's toggle settings with the
 * `aurora:application-parameter` sync command, so the toggle rows in
 * core_settings are not flagged obsolete (and wiped) — the module owns its
 * toggles instead of the central ModuleParameterEnum. Tagged
 * `aurora.application_parameter_provider` (by the central _instanceof in the
 * monorepo, or by the package's own config/services.php once split).
 */
final readonly class {{MODULE}}ModuleParameterProvider implements ApplicationParameterProviderInterface
{
    public function getParameters(): iterable
    {
        yield from {{MODULE}}ModuleParameterEnum::cases();
    }
}
