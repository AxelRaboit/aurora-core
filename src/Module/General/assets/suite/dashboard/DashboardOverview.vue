<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppTab from "@/shared/components/nav/AppTab.vue";
import { ChevronRight, Package } from "lucide-vue-next";
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

/**
 * What waits for a gesture, across every module, above the tabs.
 *
 * The dashboard was a set of figures per module, the editorial one opening
 * by default: a contract to countersign or a late review sat behind the
 * Studio tab (UI audit of 07/10/2026). Each module says what waits through
 * its registered `todo`, from the figures it already sends; a zero is left
 * out, and with nothing left the strip says so in one line.
 */
const todos = computed(() =>
    visibleModules.value
        .flatMap((module) => module.todo?.(props.stats[module.id] ?? {}) ?? [])
        .filter((item) => item.count > 0),
);

const TONES = {
    danger: "text-danger",
    warning: "text-warning",
};
</script>

<template>
    <div class="aurora-stack">
        <div v-if="visibleModules.length === 0" class="flex flex-col items-center justify-center py-24 text-center text-secondary">
            <Package class="w-10 h-10 mb-3 opacity-30" :stroke-width="1.5" />
            <p class="text-sm">{{ t('suite.stats.no_module_enabled') }}</p>
        </div>

        <template v-else>
            <section class="flex flex-col gap-2" :aria-label="t('suite.stats.todo.title')">
                <h2 class="text-xs font-semibold uppercase tracking-wider text-secondary">{{ t('suite.stats.todo.title') }}</h2>
                <p v-if="!todos.length" class="text-sm text-secondary">{{ t('suite.stats.todo.nothing') }}</p>
                <ul v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    <li v-for="item in todos" :key="item.key">
                        <component
                            :is="item.href ? 'a' : 'div'"
                            :href="item.href || undefined"
                            class="aurora-card group flex h-full items-center gap-3 px-4 py-3 no-underline transition-colors"
                            :class="item.href ? 'hover:border-accent/40 hover:bg-surface-2/40' : ''"
                        >
                            <span class="text-2xl font-semibold leading-none tracking-tight tabular-nums" :class="TONES[item.tone] ?? 'text-primary'">{{ item.count }}</span>
                            <span class="min-w-0 flex-1 text-sm text-primary/80">{{ t(item.labelKey, { count: item.count }) }}</span>
                            <ChevronRight v-if="item.href" class="h-4 w-4 shrink-0 text-muted transition-transform group-hover:translate-x-0.5" :stroke-width="2" />
                        </component>
                    </li>
                </ul>
            </section>

            <!-- The switch is a sunken strip with the open tab raised on it, no
                 outline of its own: an outline made it a fifth card above the
                 four tiles, competing with them for the eye. -->
            <div v-if="visibleModules.length > 1" class="inline-flex w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-surface-2 p-1 scrollbar-thin" role="tablist">
                <AppTab
                    v-for="module in visibleModules"
                    :key="module.id"
                    size="sm"
                    role="tab"
                    :aria-selected="activeModule === module.id ? 'true' : 'false'"
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
