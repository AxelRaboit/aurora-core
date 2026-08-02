import { computed, ref, watchEffect } from "vue";
import { useI18n } from "vue-i18n";

const ACTIVE_MODULE_KEY = "aurora-dashboard-module";

/**
 * One entry per module that draws a dashboard panel:
 * `{ id, labelKey, icon }`, where `id` matches the module id the PHP
 * DashboardViewBuilder reports as enabled. Empty until a module ships
 * both a stats provider and a panel — `visibleModules` is then empty
 * and the shell renders its empty state.
 */
const MODULE_DEFINITIONS = [];

export function useDashboardModule(enabledModules) {
    const { t } = useI18n();

    const activeModule = ref(localStorage.getItem(ACTIVE_MODULE_KEY) || "");

    const visibleModules = computed(() =>
        MODULE_DEFINITIONS.filter(
            (module) => enabledModules.value[module.id] !== false,
        ).map((module) => ({ ...module, label: () => t(module.labelKey) })),
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
