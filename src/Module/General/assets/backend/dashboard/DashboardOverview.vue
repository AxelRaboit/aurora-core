<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { Package } from "lucide-vue-next";
import { useDashboardModule } from "@general/backend/dashboard/composables/useDashboardModule.js";

/**
 * The dashboard shell. It owns the module switcher and the empty state,
 * never the panels themselves: a module contributes its figures through a
 * DashboardStatsProviderInterface on the PHP side and its panel through the
 * Core panel registry here, so this file names no module and imports none.
 * Each panel gets the slice of `stats` keyed by its own id. With nothing
 * registered, `visibleModules` is empty and the empty state is all there is
 * to draw.
 */
const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    enabledModules: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const enabledModules = computed(() => props.enabledModules);

const { activeModule, selectModule, visibleModules } = useDashboardModule(enabledModules);
</script>

<template>
    <div class="space-y-6">
        <div v-if="visibleModules.length === 0" class="flex flex-col items-center justify-center py-24 text-center text-secondary">
            <Package class="w-10 h-10 mb-3 opacity-30" :stroke-width="1.5" />
            <p class="text-sm">{{ t('backend.stats.no_module_enabled') }}</p>
        </div>

        <template v-else>
            <div v-if="visibleModules.length > 1" class="inline-flex p-1 bg-surface-2 border border-line rounded-lg gap-1 max-w-full overflow-x-auto scrollbar-thin">
                <AppTab
                    v-for="module in visibleModules"
                    :key="module.id"
                    size="sm"
                    :active="activeModule === module.id"
                    active-class="bg-surface text-primary shadow-sm"
                    inactive-class="text-secondary hover:text-primary"
                    class="whitespace-nowrap"
                    v-on:click="selectModule(module.id)"
                >
                    <component :is="module.icon" class="w-4 h-4" :stroke-width="2" />
                    {{ module.label() }}
                </AppTab>
            </div>

            <component
                :is="module.component"
                v-for="module in visibleModules"
                v-show="activeModule === module.id"
                :key="module.id"
                :stats="stats[module.id] ?? {}"
            />
        </template>
    </div>
</template>
