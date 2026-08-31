import { defineAsyncComponent, markRaw } from "vue";

/**
 * The panels modules mount inside the side menu's module view.
 *
 * A list of links covers most of what a module needs, and those are declared in
 * PHP where they get a privilege and a route. What links cannot express is a
 * folder tree or a list of nine hundred notes - so a module may name a Vue
 * component instead, via `ModuleNavView::$panelComponent`, and register it here.
 *
 * The name in the payload is the key: PHP says
 * `'ged/backend/documents/FolderTreePanel'`, the module's `*.register.js` claims
 * the same string. Same arrangement as `panelRegistry.js` for the dashboard, and
 * for the same reason - without it `AppSidemenu` would have to import from
 * `@ged/...`, which is the cross-module dependency the whole module system
 * exists to avoid, and which breaks the build the day a module ships as its own
 * package.
 *
 * `app.js` eager-globs every `*.register.js` before any Vue app mounts, so the
 * registry is full by the time the menu reads it.
 */
const panels = new Map();

/**
 * @param {string}   name      the exact string `ModuleNavView::$panelComponent` carries
 * @param {Function} component `() => import("./FolderTreePanel.vue")` - kept lazy so a
 *                             panel is downloaded only by someone who opens that module
 */
export function registerModulePanel(name, component) {
    if ("string" !== typeof name || "" === name) {
        throw new Error("registerModulePanel: name must be a non-empty string");
    }
    if (!component) {
        throw new Error(
            `registerModulePanel: component for "${name}" is required`,
        );
    }

    // Idempotent, like the dashboard registry: HMR re-runs the register files.
    panels.set(name, markRaw(defineAsyncComponent(component)));
}

/**
 * The component for a payload's `panelComponent`, or null.
 *
 * Null is the normal answer for a module whose view is links only, and it is
 * also what a typo in the PHP string gets. The menu draws its links either way
 * rather than failing - a missing panel must not cost the reader the
 * navigation that did resolve.
 */
export function getModulePanel(name) {
    if (!name) return null;

    return panels.get(name) ?? null;
}
