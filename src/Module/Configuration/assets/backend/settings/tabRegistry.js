import { markRaw } from "vue";

import NavigationTab from "@configuration/backend/settings/tabs/NavigationTab.vue";
import AppearanceTab from "@configuration/backend/settings/tabs/AppearanceTab.vue";

/**
 * Registry mapping a tab's `componentName` (declared by a
 * `ConfigurationTab` on the PHP side) to the Vue component that renders it.
 * Aurora's built-in custom tabs (navigation, appearance) are pre-registered
 * below. Aurora-client apps can plug their own components by calling
 * `registerSettingsTabComponent(name, component)` at boot, before the
 * SettingsApp mounts.
 *
 * Components must accept the standard props passed by SettingsApp:
 *  - groups        Record<tabId, fieldDescriptor[]>
 *  - updatePath    POST endpoint for setting writes
 *  - navSections   sidemenu metadata (for tabs that need it)
 *  - postSearchPath
 *
 * They are stored via `markRaw` to keep Vue's reactivity from wrapping the
 * component definitions.
 */
const components = new Map();

export function registerSettingsTabComponent(name, component) {
    if (typeof name !== "string" || name === "") {
        throw new Error(
            "registerSettingsTabComponent: name must be a non-empty string",
        );
    }
    if (!component) {
        throw new Error(
            `registerSettingsTabComponent: component for "${name}" is required`,
        );
    }
    components.set(name, markRaw(component));
}

export function getSettingsTabComponent(name) {
    if (!name) return null;
    return components.get(name) ?? null;
}

// Built-in registrations - Aurora's own custom-UI tabs.

registerSettingsTabComponent("navigation", NavigationTab);
registerSettingsTabComponent("appearance", AppearanceTab);
// Module tabs (e.g. assistant-settings) self-register via their module's
// *.register.js boot hook - aurora-core no longer imports module components.
