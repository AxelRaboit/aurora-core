<script setup>
/**
 * Des livrables en cartes : le titre qui ouvre l'éditeur, la phrase de
 * résumé, une ligne d'informations et les gestes.
 *
 * Partagé par l'onglet d'un espace et la page Livrables de Studio : seuls
 * changent les gestes, que chaque écran fournit, et ce que dit la ligne
 * d'informations, glissée par l'emplacement `meta`.
 */
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import AppCardActions from "@/shared/components/action/AppCardActions.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";

defineProps({
    deliverables: { type: Array, required: true },
    /** `(deliverable) => actions`, au format des feuilles d'actions. */
    actionsFor: { type: Function, required: true },
});

const { t } = useI18n();
const { formatDateShort } = useDateFormat();
</script>

<template>
    <ul class="m-0 list-none space-y-2 p-0">
        <li
            v-for="deliverable in deliverables"
            :key="deliverable.id"
            class="aurora-card space-y-2.5 p-3 sm:flex sm:items-center sm:gap-4 sm:space-y-0"
        >
            <div class="min-w-0 flex-1">
                <a class="block break-words text-sm font-medium text-primary no-underline hover:text-accent" :href="deliverable.editPath">
                    {{ deliverable.title }}
                </a>
                <p v-if="deliverable.summary" class="m-0 mt-0.5 text-xs text-secondary line-clamp-2">{{ deliverable.summary }}</p>
                <p class="m-0 mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                    <slot name="meta" :deliverable="deliverable" />
                    <span>{{ t("backend.studio.deliverables.updated_on", { date: formatDateShort(deliverable.updatedAt) }) }}</span>
                </p>
            </div>

            <!-- Le menu sur ordinateur ; sur téléphone les gestes sont
                 écrits en toutes lettres sous la carte. -->
            <AppRowActions class="hidden shrink-0 sm:block" :actions="actionsFor(deliverable)" :label="deliverable.title" />
            <div class="border-t border-line/40 pt-2 sm:hidden">
                <AppCardActions :actions="actionsFor(deliverable)" />
            </div>
        </li>
    </ul>
</template>
