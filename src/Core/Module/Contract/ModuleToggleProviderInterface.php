<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Contract;

use Aurora\Core\Module\Toggle\ModuleToggle;

/**
 * Optional companion interface for {@see ModuleInterface}: lets a module
 * declare its module-access toggles (settings keys + cascade graph) so they
 * are aggregated by {@see ModuleToggleRegistry} and consumed by
 * {@see ModuleAccessChecker} (global + per-user + cascade) and by
 * `UsersViewBuilder` (per-user access picker UI).
 *
 * Aurora-core's own modules implement this and expose their
 * `ModuleParameterEnum` cases as toggles. Aurora-client modules implement
 * the same interface to plug their custom modules (e.g. "tracking",
 * "marketing-automation") into the same machinery - no patch on core.
 *
 * Module classes that do not need their own toggles can simply NOT implement
 * this interface; the registry will skip them.
 */
interface ModuleToggleProviderInterface
{
    /** @return list<ModuleToggle> */
    public function getToggles(): array;
}
