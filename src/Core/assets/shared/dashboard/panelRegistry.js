import { defineAsyncComponent, markRaw } from "vue";

/**
 * The dashboard panels modules contribute.
 *
 * The PHP side already keeps the General shell free of business modules —
 * figures arrive through DashboardStatsProviderInterface — and this is the
 * same arrangement for the Vue side. Without it the shell would have to
 * `import EditorialPanel from "@editorial/..."`, which is exactly the
 * cross-module dependency the provider registry exists to avoid, and which
 * would also break the build the day Editorial ships as its own package.
 *
 * A module registers from a `*.register.js` file; app.js eager-globs those
 * before any Vue app mounts, so the registry is full by the time the shell
 * reads it.
 */
const panels = [];

/**
 * @param {object}   panel
 * @param {string}   panel.id        matches the module id the PHP side reports enabled
 * @param {string}   panel.labelKey  i18n key for the module switcher tab
 * @param {object}   panel.icon      lucide component for that tab
 * @param {Function} panel.component `() => import("...")` — kept lazy so a panel
 *                                   nobody looks at is never downloaded
 */
export function registerDashboardPanel({ id, labelKey, icon, component }) {
    // Registration is idempotent: HMR re-runs the register files, and a
    // duplicated tab is a confusing way to find that out.
    const existing = panels.findIndex((panel) => panel.id === id);
    const entry = {
        id,
        labelKey,
        icon: markRaw(icon),
        component: markRaw(defineAsyncComponent(component)),
    };

    if (existing === -1) panels.push(entry);
    else panels[existing] = entry;
}

/** @returns {Array<{id: string, labelKey: string, icon: object, component: object}>} */
export function dashboardPanels() {
    return panels;
}
