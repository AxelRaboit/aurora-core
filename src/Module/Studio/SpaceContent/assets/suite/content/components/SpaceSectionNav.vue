<script setup>
/**
 * A space's sections, grouped.
 *
 * **A rail on a computer, a sheet elsewhere.** Ten tabs on one line overflowed
 * from 1,024 pixels, and on a phone only icons were left to guess. Grouped by
 * what people do there - work, file documents, know the client - they fit in a
 * column read at a glance. Below `lg`, a button says where you are and opens
 * the same list: the name of the open section answers "where am I", the list
 * "where to go".
 *
 * **A counter when there is something to do, and only then.** The number of
 * files or notes calls for no action; posts waiting for the client's opinion
 * do. A counter on every entry would teach people to read none of them.
 *
 * The entries come from the caller, already filtered (the Drive without a
 * service account, the settings for whoever does not configure); this
 * component only decides where they go.
 */
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown } from "lucide-vue-next";
import AppModal from "@/shared/components/overlay/AppModal.vue";

const props = defineProps({
    /** `{ key, labelKey, icon }`, in the bar's order. */
    views: { type: Array, required: true },
    /** Per section key, a number that calls for an action. */
    badges: { type: Object, default: () => ({}) },
    /** The keys whose counter signals a delay. */
    urgent: { type: Array, default: () => [] },
});

const view = defineModel({ type: String, required: true });

const { t } = useI18n();

/** What people do in each section; Settings, apart, closes the list. */
const GROUPS = [
    { key: "work", views: ["content", "calendar", "chat"] },
    { key: "documents", views: ["files", "drive", "deliverables", "notes"] },
    { key: "client", views: ["information", "resources"] },
];

const byKey = computed(() => Object.fromEntries(props.views.map((entry) => [entry.key, entry])));

/** The groups with at least one entry, and a section unknown to the groups at the end. */
const groups = computed(() => {
    const placed = new Set(GROUPS.flatMap((group) => group.views));
    const result = GROUPS.map((group) => ({
        key: group.key,
        entries: group.views.map((key) => byKey.value[key]).filter(Boolean),
    })).filter((group) => group.entries.length);

    const rest = props.views.filter((entry) => !placed.has(entry.key) && "settings" !== entry.key);
    if (rest.length) result.push({ key: "other", entries: rest });

    return result;
});

const settings = computed(() => byKey.value.settings ?? null);
const current = computed(() => byKey.value[view.value] ?? props.views[0]);

const sheetOpen = ref(false);

function go(key) {
    view.value = key;
    sheetOpen.value = false;
}

function badgeOf(key) {
    const count = props.badges[key] ?? 0;

    return count > 0 ? count : null;
}
</script>

<template>
    <!-- Computer: the rail, stuck under the header while scrolling. -->
    <nav
        class="sticky top-20 hidden w-52 shrink-0 flex-col gap-5 self-start lg:flex"
        :aria-label="t('suite.studio.space_content.view_label')"
    >
        <div v-for="group in groups" :key="group.key" class="flex flex-col gap-1">
            <p class="m-0 px-2.5 text-xs font-semibold uppercase tracking-wider text-secondary">
                {{ t(`suite.studio.space_content.nav_groups.${group.key}`) }}
            </p>
            <button
                v-for="entry in group.entries"
                :key="entry.key"
                type="button"
                class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-sm transition-colors"
                :class="view === entry.key ? 'bg-accent/10 font-medium text-accent shadow-[inset_2px_0_0_var(--color-accent-500)]' : 'text-secondary hover:bg-surface-2/60 hover:text-primary'"
                :aria-current="view === entry.key ? 'page' : undefined"
                v-on:click="go(entry.key)"
            >
                <component
                    :is="entry.icon"
                    class="h-4 w-4 shrink-0"
                    :class="view === entry.key ? 'text-accent-400' : 'text-muted'"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ t(entry.labelKey) }}</span>
                <span
                    v-if="badgeOf(entry.key)"
                    class="rounded-full px-1.5 text-2xs font-semibold tabular-nums"
                    :class="urgent.includes(entry.key) ? 'bg-warning-soft text-warning' : 'bg-accent-500/15 text-accent-400'"
                >
                    {{ badgeOf(entry.key) }}
                </span>
            </button>
        </div>

        <div v-if="settings" class="border-t border-line pt-3">
            <button
                type="button"
                class="flex w-full items-center gap-2.5 rounded-md px-2.5 py-1.5 text-left text-sm transition-colors"
                :class="view === settings.key ? 'bg-accent/10 font-medium text-accent shadow-[inset_2px_0_0_var(--color-accent-500)]' : 'text-secondary hover:bg-surface-2/60 hover:text-primary'"
                :aria-current="view === settings.key ? 'page' : undefined"
                v-on:click="go(settings.key)"
            >
                <component
                    :is="settings.icon"
                    class="h-4 w-4 shrink-0"
                    :class="view === settings.key ? 'text-accent-400' : 'text-muted'"
                    :stroke-width="2"
                />
                <span class="min-w-0 flex-1 truncate">{{ t(settings.labelKey) }}</span>
            </button>
        </div>
    </nav>

    <!-- Phone and tablet: the open section, and the list behind it. -->
    <button
        type="button"
        class="flex min-w-0 items-center gap-2 rounded-lg border border-line bg-surface-2/40 px-3 py-2 text-sm font-medium text-primary transition-colors hover:bg-surface-2 lg:hidden"
        :aria-label="t('suite.studio.space_content.nav_open')"
        aria-haspopup="dialog"
        v-on:click="sheetOpen = true"
    >
        <component :is="current.icon" class="h-4 w-4 shrink-0 text-accent-400" :stroke-width="2" />
        <span class="min-w-0 truncate">{{ t(current.labelKey) }}</span>
        <span
            v-if="Object.values(badges).some((count) => count > 0)"
            class="h-2 w-2 shrink-0 rounded-full bg-accent-400"
            aria-hidden="true"
        />
        <ChevronDown class="ml-auto h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
    </button>

    <AppModal :show="sheetOpen" max-width="sm" :title="t('suite.studio.space_content.nav_title')" v-on:close="sheetOpen = false">
        <div class="flex flex-col gap-4">
            <div v-for="group in groups" :key="group.key" class="flex flex-col gap-1">
                <p class="m-0 px-2.5 text-xs font-semibold uppercase tracking-wider text-secondary">
                    {{ t(`suite.studio.space_content.nav_groups.${group.key}`) }}
                </p>
                <button
                    v-for="entry in group.entries"
                    :key="entry.key"
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2.5 text-left text-sm transition-colors"
                    :class="view === entry.key ? 'bg-accent/10 font-medium text-accent shadow-[inset_2px_0_0_var(--color-accent-500)]' : 'text-secondary hover:bg-surface-2/60'"
                    :aria-current="view === entry.key ? 'page' : undefined"
                    v-on:click="go(entry.key)"
                >
                    <component :is="entry.icon" class="h-4 w-4 shrink-0" :class="view === entry.key ? 'text-accent-400' : 'text-muted'" :stroke-width="2" />
                    <span class="min-w-0 flex-1 truncate">{{ t(entry.labelKey) }}</span>
                    <span
                        v-if="badgeOf(entry.key)"
                        class="rounded-full px-1.5 text-2xs font-semibold tabular-nums"
                        :class="urgent.includes(entry.key) ? 'bg-warning-soft text-warning' : 'bg-accent-500/15 text-accent-400'"
                    >
                        {{ badgeOf(entry.key) }}
                    </span>
                </button>
            </div>
            <!-- The rule on a container, not on the button: on a rounded
                 button, it followed the rounding and drew an arc. -->
            <div v-if="settings" class="border-t border-line pt-3">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-2.5 py-2.5 text-left text-sm transition-colors"
                    :class="view === settings.key ? 'bg-accent/10 font-medium text-accent shadow-[inset_2px_0_0_var(--color-accent-500)]' : 'text-secondary hover:bg-surface-2/60'"
                    :aria-current="view === settings.key ? 'page' : undefined"
                    v-on:click="go(settings.key)"
                >
                    <component
                        :is="settings.icon"
                        class="h-4 w-4 shrink-0"
                        :class="view === settings.key ? 'text-accent-400' : 'text-muted'"
                        :stroke-width="2"
                    />
                    <span class="min-w-0 flex-1 truncate">{{ t(settings.labelKey) }}</span>
                </button>
            </div>
        </div>
    </AppModal>
</template>
