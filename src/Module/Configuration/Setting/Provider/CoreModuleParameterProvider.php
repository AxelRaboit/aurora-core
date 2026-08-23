<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Provider;

use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * Default provider for aurora-core's `ModuleParameterEnum` (module
 * toggle settings - modules_<x>_backend/_frontend keys driving the
 * /dev/dashboard/modules admin). Auto-discovered by the
 * `aurora:application-parameter` command via the tagged iterator.
 */
final readonly class CoreModuleParameterProvider implements ApplicationParameterProviderInterface
{
    public function getParameters(): iterable
    {
        yield from ModuleParameterEnum::cases();
    }
}
