<script setup>
/**
 * Ce qui entoure un client : ses contrats, ses livrables de Studio, ses
 * espaces, en liens.
 *
 * Le même bloc sur sa page et dans l'onglet Informations de chacun de ses
 * espaces, comme le serveur les calcule d'une seule façon
 * (`CustomerRelatedViewBuilder`). Une liste à `null` est une liste que le
 * lecteur ne peut pas ouvrir : elle ne se montre pas, plutôt que d'aligner des
 * liens qui répondraient 403.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps({
    /** `{contracts, deliverables, spaces}`, chacune une liste ou null. */
    related: { type: Object, default: () => ({}) },
    /** Vu depuis un espace : les autres espaces seulement, et le titre le dit. */
    fromSpace: { type: Boolean, default: false },
});

const { t } = useI18n();

const R = "suite.studio.customers.related";

/** Les trois listes qui ont quelque chose à montrer, dans l'ordre où on les consulte. */
const groups = computed(() =>
    [
        { key: "contracts", title: t(`${R}.contracts`), rows: props.related?.contracts },
        { key: "deliverables", title: t(`${R}.deliverables`), rows: props.related?.deliverables },
        { key: "spaces", title: t(props.fromSpace ? `${R}.other_spaces` : `${R}.spaces`), rows: props.related?.spaces },
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
        <p v-else class="m-0 text-xs text-muted">{{ t(fromSpace ? `${R}.empty_other` : `${R}.empty`) }}</p>
        <slot />
    </div>
</template>
