<?php

declare(strict_types=1);

namespace Aurora\Core\Module\Toggle;

/**
 * Declarative description of a single module toggle (one row in core_settings,
 * one entry of `ModuleParameterEnum` for core modules - or any custom key
 * declared by a client module).
 *
 * Each module describes its own toggles via {@see ModuleToggleProviderInterface}.
 * Aurora-core registers all `ModuleParameterEnum` cases through this mechanism,
 * and aurora-client modules can register additional toggles the same way.
 *
 * @see ModuleToggleRegistry  aggregation point
 * @see ModuleAccessChecker   consumer (global + per-user + cascade)
 */
final readonly class ModuleToggle
{
    public function __construct(
        /** Stored key in `core_settings` and in `core_users.disabled_modules`. */
        public string $key,
        /** Translation key for the human label (sidemenu, picker). */
        public string $labelKey,
        /** Translation key for the longer description (tooltip, settings page). */
        public string $descriptionKey,
        /**
         * Optional parent toggle key - when the parent is OFF (globally or
         * per-user), this child is treated as OFF too. Matches the existing
         * `ModuleParameterEnum::getCascadeRequires()` semantics.
         */
        public ?string $parentKey = null,
        /**
         * Non-null only for the "top-level" toggle of a given module
         * (one per module). Used by `UsersViewBuilder` to surface modules
         * in the admin module-access picker without exposing every sub-toggle.
         */
        public ?string $moduleId = null,
        /**
         * Optional structural parent for DISPLAY grouping in the modules
         * dashboard - the module's top-level toggle this sub-toggle nests under
         * (vs {@see $parentKey} which is the cascade dependency, possibly a
         * sibling). Null for top-level toggles. Matches
         * `ModuleParameterEnum::getParentCase()` semantics.
         */
        public ?string $displayParentKey = null,
    ) {}

    public function isTopLevel(): bool
    {
        return null !== $this->moduleId;
    }
}
