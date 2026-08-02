import {
    startStimulusApp,
    registerControllers,
} from "vite-plugin-symfony/stimulus/helpers";
import { registerVueControllerComponents } from "@symfony/ux-vue";
import { createAppI18n } from "@/i18n.js";
// Gate 2 (option B): Vue components of sibling module packages
// (vendor/axelraboit/aurora-*) when aurora-core runs as a vendored package.
// Empty object in the monorepo (the plugin is a no-op there). See
// vite-plugin-aurora-modules.js.
import vendorModules from "virtual:aurora-vendor-modules";
import "./shared/utils/loader.js";
import "./css/app.css";

// Module boot hooks: a module may ship `*.register.js` files (e.g. a settings
// tab registration) that run side effects at boot — so aurora-core never imports
// module components directly. Eager-loaded here, before any Vue app mounts.
// Monorepo + client modules via glob; vendored module packages via the virtual
// boot module (see vite-plugin-aurora-modules.js). Bare call expressions: the
// returned maps are unused, only the side effects matter.
import "virtual:aurora-vendor-boot";
import.meta.glob("../../Module/**/assets/**/*.register.js", { eager: true });
import.meta.glob("@client/src/Module/**/assets/**/*.register.js", {
    eager: true,
});

document.addEventListener("vue:before-mount", (event) => {
    const locale = document.documentElement.lang || "fr";
    event.detail.app.use(createAppI18n(locale));
});

const app = startStimulusApp();

registerControllers(
    app,
    import.meta.glob("./stimulus/*_controller.js", {
        query: "?stimulus",
        eager: true,
    }),
);

// Core Vue components (backend + frontend cross-cutting), living alongside
// the PHP Aurora\Core\Frontend\ namespace.
const coreModules = import.meta.glob([
    "./backend/**/*.vue",
    "./frontend/**/*.vue",
]);

// All first-party module Vue components (backend + frontend) — auto-discovered.
// The `**/assets/` pattern accepts any depth of feature folders between
// Module/<Name>/ and assets/, so e.g. both ./Module/Ged/assets/... AND
// ./Module/Ged/Document/assets/... resolve. Feature folders are flattened
// away in the exposed key (cf. mapping regex below) — the module name is
// always the FIRST segment under Module/.
const auroraModules = import.meta.glob("../../Module/**/assets/**/*.vue");

// Optional client extension modules. Resolves via the @client alias which
// points to AURORA_CLIENT_DIR (or an empty fallback when unset). Mirrors
// aurora-core's own layout: components live under
// <client>/src/Module/<Name>/[<FeatureFolders>/]assets/backend/Foo.vue and
// are exposed as ./<name>/backend/Foo.vue so the client uses
// vue_component('<name>/backend/Foo') in Twig — same convention as aurora's
// first-party modules. Co-locating override Vue with the PHP extension
// (e.g. src/Module/Ged/assets/backend/documents/DocumentsApp.vue)
// shadows aurora's `ged/backend/documents/DocumentsApp` because clientModules
// is spread AFTER auroraModules below — same key, client wins.
const clientModules = import.meta.glob("@client/src/Module/**/assets/**/*.vue");

// Client overrides — escape hatch for non-module Aurora components (e.g. things
// living under aurora-core's src/Core/Frontend/ that don't have a module prefix).
// For Aurora MODULE component overrides, prefer co-locating under
// src/Module/<Name>/[<FeatureFolders>/]assets/ — that uses clientModules and
// is co-located with the corresponding PHP extension.
const clientOverrides = import.meta.glob("@client/src/Overrides/**/*.vue");

// Regex: capture the FIRST segment under Module/ as the module name, skip any
// number of feature folders, then capture everything after /assets/.
// Module/Ged/assets/backend/X.vue              → moduleName=Ged, rest=backend/X.vue
// Module/Ged/Document/assets/backend/X.vue     → moduleName=Ged, rest=backend/X.vue
const MODULE_PATH_RE = /Module\/([^/]+)\/(?:[^/]+\/)*assets\/(.*)$/;

const vueContext = {
    // Core: ./backend/Foo.vue → ./core/backend/Foo.vue (./frontend/ likewise)
    ...Object.fromEntries(
        Object.entries(coreModules).map(([key, loader]) => [
            key.replace(/^\.\//, "./core/"),
            loader,
        ]),
    ),
    // Aurora modules: feature folders (if any) are flattened away.
    ...Object.fromEntries(
        Object.entries(auroraModules).map(([key, loader]) => {
            const match = key.match(MODULE_PATH_RE);
            if (!match) return [key, loader];
            const [, moduleName, rest] = match;
            return [`./${moduleName.toLowerCase()}/${rest}`, loader];
        }),
    ),
    // Vendored module packages (vendor/axelraboit/aurora-*) — already keyed as
    // ./<module>/<rest>. Spread AFTER auroraModules (in the monorepo this is
    // empty; in a client install src/Module is empty and these provide the
    // modules) and BEFORE clientModules so the client can still override them.
    ...vendorModules,
    // Client modules: same mapping. Spread AFTER auroraModules so that a
    // client file at the same key wins (= shadow Aurora's own component).
    ...Object.fromEntries(
        Object.entries(clientModules).map(([key, loader]) => {
            const match = key.match(MODULE_PATH_RE);
            if (!match) return [key, loader];
            const [, moduleName, rest] = match;
            return [`./${moduleName.toLowerCase()}/${rest}`, loader];
        }),
    ),
    // Client overrides: expose path AFTER `Overrides/` as-is. Useful for
    // shadowing non-module Aurora components (e.g., put a file at
    // src/Overrides/core/backend/Foo.vue to shadow `core/backend/Foo`).
    ...Object.fromEntries(
        Object.entries(clientOverrides).map(([key, loader]) => {
            const match = key.match(/Overrides\/(.*)$/);
            if (!match) return [key, loader];
            const [, rest] = match;
            return [`./${rest}`, loader];
        }),
    ),
};

const vueContextFn = (key) => vueContext[key]();
vueContextFn.keys = () => Object.keys(vueContext);
registerVueControllerComponents(vueContextFn);
