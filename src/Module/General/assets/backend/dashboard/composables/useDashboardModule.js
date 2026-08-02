import { computed, ref, watchEffect } from "vue";
import { useI18n } from "vue-i18n";
import { dashboardPanels } from "@/shared/dashboard/panelRegistry.js";

const ACTIVE_MODULE_KEY = "aurora-dashboard-module";

/**
 * Panels come from the Core registry, filled by each module's
 * `*.register.js` at boot — the shell names no module and imports none.
 * With nothing registered, `visibleModules` is empty and the shell renders
 * its empty state.
 */

export function useDashboardModule(enabledModules) {
    const { t } = useI18n();

    const activeModule = ref(localStorage.getItem(ACTIVE_MODULE_KEY) || "");

    const visibleModules = computed(() =>
        dashboardPanels()
            .filter((module) => enabledModules.value[module.id] !== false)
            .map((module) => ({ ...module, label: () => t(module.labelKey) })),
    );

    function selectModule(id) {
        activeModule.value = id;
        localStorage.setItem(ACTIVE_MODULE_KEY, id);
    }

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
