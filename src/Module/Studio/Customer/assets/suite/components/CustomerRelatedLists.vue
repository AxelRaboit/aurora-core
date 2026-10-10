<script setup>
/**
 * What surrounds a customer: their contracts, their Studio deliverables,
 * their spaces, as links.
 *
 * The same block on their page and in the Informations tab of each of their
 * spaces, as the server computes them in a single way
 * (`CustomerRelatedViewBuilder`). A `null` list is a list the reader cannot
 * open: it is not shown, rather than lining up links that would answer 403.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { contractStatusColor } from "@/shared/utils/format/statusStyles.js";

const props = defineProps({
    /** `{contracts, deliverables, spaces}`, each a list or null. */
    related: { type: Object, default: () => ({}) },
    /** Seen from a space: the other spaces only, and the title says so. */
    fromSpace: { type: Boolean, default: false },
    /**
     * Their complete lists, `{contracts, spaces}`, each a path or null: a
     * "All" link beside the title of the list it completes.
     */
    allPaths: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const RELATED_KEYS = "suite.studio.customers.related";

/** The three lists that have something to show, in the order they are consulted. */
const groups = computed(() =>
    [
        { key: "contracts", title: t(`${RELATED_KEYS}.contracts`), rows: props.related?.contracts, allPath: props.allPaths?.contracts },
        { key: "deliverables", title: t(`${RELATED_KEYS}.deliverables`), rows: props.related?.deliverables, allPath: null },
        { key: "spaces", title: t(props.fromSpace ? `${RELATED_KEYS}.other_spaces` : `${RELATED_KEYS}.spaces`), rows: props.related?.spaces, allPath: props.allPaths?.spaces },
    ].filter((group) => Array.isArray(group.rows) && group.rows.length),
);

/**
 * A contract's status in the colours of the contracts list, so the same word
 * reads the same everywhere; what a deliverable or a space says beside its
 * name (format, archived) stays grey (visual redesign of the suite,
 * 10/10/2026).
 */
function badgeColor(row) {
    return row.status ? contractStatusColor(row.status) : "gray";
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <template v-if="groups.length">
            <section v-for="group in groups" :key="group.key" class="flex flex-col gap-1.5" :data-related="group.key">
                <div class="flex items-baseline justify-between gap-3">
                    <h3 class="m-0 text-xs font-semibold uppercase tracking-wider text-secondary">{{ group.title }}</h3>
                    <a v-if="group.allPath" :href="group.allPath" class="shrink-0 text-xs text-accent-500 hover:underline">
                        {{ t(`${RELATED_KEYS}.all`) }}
                    </a>
                </div>
                <ul class="m-0 list-none divide-y divide-line/60 p-0">
                    <li v-for="row in group.rows" :key="row.url">
                        <a :href="row.url" class="flex items-center justify-between gap-3 py-2 text-sm text-primary no-underline hover:text-accent-500 hover:underline">
                            <span class="min-w-0 truncate tabular-nums">{{ row.label }}</span>
                            <AppBadge v-if="row.detail" :color="badgeColor(row)" class="shrink-0">{{ row.detail }}</AppBadge>
                        </a>
                    </li>
                </ul>
            </section>
        </template>
        <p v-else class="m-0 text-xs text-muted">{{ t(fromSpace ? `${RELATED_KEYS}.empty_other` : `${RELATED_KEYS}.empty`) }}</p>
        <slot />
    </div>
</template>
