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

const props = defineProps({
    /** `{contracts, deliverables, spaces}`, each a list or null. */
    related: { type: Object, default: () => ({}) },
    /** Seen from a space: the other spaces only, and the title says so. */
    fromSpace: { type: Boolean, default: false },
});

const { t } = useI18n();

const RELATED_KEYS = "suite.studio.customers.related";

/** The three lists that have something to show, in the order they are consulted. */
const groups = computed(() =>
    [
        { key: "contracts", title: t(`${RELATED_KEYS}.contracts`), rows: props.related?.contracts },
        { key: "deliverables", title: t(`${RELATED_KEYS}.deliverables`), rows: props.related?.deliverables },
        { key: "spaces", title: t(props.fromSpace ? `${RELATED_KEYS}.other_spaces` : `${RELATED_KEYS}.spaces`), rows: props.related?.spaces },
    ].filter((group) => Array.isArray(group.rows) && group.rows.length),
);
</script>

<template>
    <div class="flex flex-col gap-4">
        <template v-if="groups.length">
            <section v-for="group in groups" :key="group.key" class="flex flex-col gap-1.5" :data-related="group.key">
                <h3 class="m-0 text-xs uppercase tracking-wide text-muted">{{ group.title }}</h3>
                <ul class="m-0 list-none divide-y divide-line/60 p-0">
                    <li v-for="row in group.rows" :key="row.url">
                        <a :href="row.url" class="flex items-center justify-between gap-3 py-1.5 text-sm text-primary no-underline hover:text-accent-500 hover:underline">
                            <span class="min-w-0 truncate">{{ row.label }}</span>
                            <span v-if="row.detail" class="shrink-0 text-xs text-muted">{{ row.detail }}</span>
                        </a>
                    </li>
                </ul>
            </section>
        </template>
        <p v-else class="m-0 text-xs text-muted">{{ t(fromSpace ? `${RELATED_KEYS}.empty_other` : `${RELATED_KEYS}.empty`) }}</p>
        <slot />
    </div>
</template>
