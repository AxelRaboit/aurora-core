<?php

declare(strict_types=1);

namespace Aurora\Module\Configuration\Setting\Configuration;

use Aurora\Module\Configuration\Setting\Enum\ModuleParameterEnum;

/**
 * One tab in the admin Settings page. The `$id` is both the persisted
 * "group" identifier (matching the legacy `ApplicationParameterEnum::getGroup()`
 * values) and the translation-key suffix the Vue layer uses to resolve the
 * tab label / description (`backend.settings.tabs.{id}` + `_description`).
 *
 * @phpstan-import-type SelectOption from SettingFieldDescriptor
 */
class ConfigurationTab
{
    /**
     * @param list<SettingFieldDescriptor> $fields
     */
    public function __construct(
        public readonly string $id,
        public readonly int $priority,
        public readonly array $fields,
        /**
         * Forces the tab to render even when `$fields` is empty - for tabs
         * whose body is a custom Vue component (e.g. navigation aliases,
         * appearance palette) that draws its own content.
         */
        public readonly bool $alwaysVisible = false,
        /**
         * When true, this tab is only rendered for users with ROLE_DEV.
         * Regular admins do not see it. Use for technical / low-level settings
         * that should not be touched in normal operation (sequences, upload
         * limits, internal prefixes, etc.).
         */
        public readonly bool $devOnly = false,
        /**
         * Optional name resolved against the Vue-side tab registry
         * (`src/Module/Configuration/assets/backend/settings/tabRegistry.js`). When set, the
         * Settings page renders the matching component instead of the
         * generic field renderer; clients can plug their own components
         * via `registerSettingsTabComponent(name, component)`.
         *
         * Note: the registry name lives on the JS side; the backend only
         * carries the string so the Vue layer can look it up.
         */
        public readonly ?string $componentName = null,
        /**
         * When set, the tab is only rendered while the module toggle is
         * enabled (resolved through `ModuleAccessChecker::isEnabled()`).
         * Disabling the module via `/dev/dashboard/modules` immediately
         * hides the tab from `/backend/configuration/settings` so the UI stays consistent
         * with what's actually accessible. Pass a `ModuleParameterEnum`
         * case for core modules or a raw toggle key string for client
         * modules whose top-level toggle isn't part of aurora-core's enum
         * (e.g. `'modules_<module_id>_backend'`).
         *
         * Shared tabs that aggregate fields across modules (notably
         * `sequences`) MUST leave this null - they should remain visible
         * as long as at least one contributing module is enabled, and the
         * merged-field semantics already handle that.
         */
        public readonly ModuleParameterEnum|string|null $moduleToggle = null,
    ) {}
}
