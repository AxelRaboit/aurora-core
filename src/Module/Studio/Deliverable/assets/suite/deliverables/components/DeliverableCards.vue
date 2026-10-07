<script setup>
/**
 * Deliverables as cards: the title that opens the editor, the summary
 * sentence, an information line and the actions.
 *
 * Shared by a space's tab and Studio's Deliverables page: only the actions
 * change, which each screen provides, and what the information line says,
 * passed through the `meta` slot.
 *
 * The deliverable's image opens the card, on the left: you spot a template
 * by its thumbnail before reading its title. Without an image, a neutral
 * tile holds the place, so that titles stay aligned from one card to the
 * next.
 */
import { useI18n } from "vue-i18n";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { FileText } from "lucide-vue-next";

defineProps({
    deliverables: { type: Array, required: true },
    /** `(deliverable) => actions`, in the action sheet format. */
    actionsFor: { type: Function, required: true },
});

const { t } = useI18n();
const { formatDateShort, formatTime } = useDateFormat();
</script>

<template>
    <ul class="m-0 list-none space-y-2 p-0" role="list">
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
                        <span>{{ t("suite.studio.deliverables.updated_on", { date: formatDateShort(deliverable.updatedAt), time: formatTime(deliverable.updatedAt) }) }}</span>
                    </p>
                </div>
            </div>

            <!-- The "…" menu on every screen, as on every list
                 (Axel's decision of 04/10/2026). -->
            <AppRowActions class="shrink-0" :actions="actionsFor(deliverable)" :label="deliverable.title" />
        </li>
    </ul>
</template>
