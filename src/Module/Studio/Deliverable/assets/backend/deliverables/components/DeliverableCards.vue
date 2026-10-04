<script setup>
/**
 * Des livrables en cartes : le titre qui ouvre l'éditeur, la phrase de
 * résumé, une ligne d'informations et les gestes.
 *
 * Partagé par l'onglet d'un espace et la page Livrables de Studio : seuls
 * changent les gestes, que chaque écran fournit, et ce que dit la ligne
 * d'informations, glissée par l'emplacement `meta`.
 *
 * L'image du livrable ouvre la carte, à gauche : on repère un modèle à sa
 * vignette avant de lire son titre. Sans image, une tuile neutre garde la
 * place, pour que les titres restent alignés d'une carte à l'autre.
 */
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { FileText } from "lucide-vue-next";

defineProps({
    deliverables: { type: Array, required: true },
    /** `(deliverable) => actions`, au format des feuilles d'actions. */
    actionsFor: { type: Function, required: true },
});

const { t } = useI18n();
const { formatDateShort, formatTime } = useDateFormat();
</script>

<template>
    <ul class="m-0 list-none space-y-2 p-0">
        <li
            v-for="deliverable in deliverables"
            :key="deliverable.id"
            class="aurora-card flex items-start gap-3 p-3 sm:items-center sm:gap-4"
        >
            <div class="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
                <a
                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-line bg-surface-2 sm:h-14 sm:w-14"
                    :href="deliverable.editPath"
                    tabindex="-1"
                    aria-hidden="true"
                >
                    <img
                        v-if="deliverable.thumbnailUrl"
                        :src="deliverable.thumbnailUrl"
                        alt=""
                        loading="lazy"
                        class="h-full w-full object-cover"
                        :style="{ objectPosition: deliverable.thumbnailPosition || '50% 50%' }"
                    >
                    <FileText v-else class="h-5 w-5 text-muted" :stroke-width="1.75" />
                </a>
                <div class="min-w-0 flex-1">
                    <a class="block break-words text-sm font-medium text-primary no-underline hover:text-accent" :href="deliverable.editPath">
                        {{ deliverable.title }}
                    </a>
                    <p v-if="deliverable.summary" class="m-0 mt-0.5 text-xs text-secondary line-clamp-2">{{ deliverable.summary }}</p>
                    <p class="m-0 mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                        <slot name="meta" :deliverable="deliverable" />
                        <span>{{ t("backend.studio.deliverables.updated_on", { date: formatDateShort(deliverable.updatedAt), time: formatTime(deliverable.updatedAt) }) }}</span>
                    </p>
                </div>
            </div>

            <!-- Le menu « … » sur tous les écrans, comme sur toutes les listes
                 (décision d'Axel du 04/10/2026). -->
            <AppRowActions class="shrink-0" :actions="actionsFor(deliverable)" :label="deliverable.title" />
        </li>
    </ul>
</template>
