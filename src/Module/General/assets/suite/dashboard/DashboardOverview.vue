<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { Package } from "lucide-vue-next";
import { useDashboardModule } from "@general/suite/dashboard/composables/useDashboardModule.js";

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
    <div class="aurora-stack">
        <div v-if="visibleModules.length === 0" class="flex flex-col items-center justify-center py-24 text-center text-secondary">
            <Package class="w-10 h-10 mb-3 opacity-30" :stroke-width="1.5" />
            <p class="text-sm">{{ t('suite.stats.no_module_enabled') }}</p>
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
                    <!-- **On phone, only the open tab carries its name.**
                         Five labels take four hundred and forty-nine pixels
                         for a three hundred and fifty-nine pixel strip: it
                         scrolled sideways on the landing screen, the one
                         opened most often. The icon answers "where can I
                         go", the name of the open tab "where am I" - and it
                         is the only one of the two questions that needs
                         words. The label stays readable by a screen reader,
                         and comes back in full from `sm`. -->
                    <component :is="module.icon" class="w-4 h-4 shrink-0" :stroke-width="2" />
                    <span :class="activeModule === module.id ? '' : 'sr-only sm:not-sr-only'">
                        {{ module.label() }}
                    </span>
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
