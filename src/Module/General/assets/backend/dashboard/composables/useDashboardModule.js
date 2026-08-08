import { computed, watchEffect } from "vue";
import { useI18n } from "vue-i18n";
import { dashboardPanels } from "@/shared/dashboard/panelRegistry.js";
import { useQueryState } from "@/shared/composables/useQueryState.js";

/**
 * Panels come from the Core registry, filled by each module's
 * `*.register.js` at boot — the shell names no module and imports none.
 * With nothing registered, `visibleModules` is empty and the shell renders
 * its empty state.
 */

export function useDashboardModule(enabledModules) {
    const { t } = useI18n();

    // In the URL: which module's dashboard is on screen is what the page is
    // showing, so a link to it should say so. Validated on read rather than
    // against a fixed list — the panels come from a registry each module
    // fills at boot, so what is valid is only known here.
    const { value: activeModule, set: selectModule } = useQueryState("module");

    const visibleModules = computed(() =>
        dashboardPanels()
            .filter((module) => enabledModules.value[module.id] !== false)
            .map((module) => ({ ...module, label: () => t(module.labelKey) })),
    );

    // A module named in the URL that is disabled, gone, or never existed
    // falls back to the first one rather than showing an empty shell.
    watchEffect(() => {
        if (
            visibleModules.value.length > 0 &&
            !visibleModules.value.find((m) => m.id === activeModule.value)
        ) {
            selectModule(visibleModules.value[0].id);
        }
    });

    return { activeModule, selectModule, visibleModules };
}
