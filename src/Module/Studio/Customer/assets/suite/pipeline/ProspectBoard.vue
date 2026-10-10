<script setup>
/**
 * The prospects as a board: one column per stage, cards dragged between them.
 *
 * Presentational, like the board of a space: the data and the writes belong
 * to `useProspectPipeline`, this draws them and reports the gestures.
 *
 * **The outcome stages say how far back they go.** Won and lost only show
 * what was decided in the last weeks; without saying so, an emptying column
 * reads as deals disappearing.
 */
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { GripVertical, Pencil, Plus, Trash2 } from "lucide-vue-next";
import { useMoneyFormat } from "@/shared/composables/format/useMoneyFormat.js";
import ProspectCard from "./components/ProspectCard.vue";
import { stageTotals } from "./composables/useProspectPipeline.js";

defineProps({
    /** `[{stage, cards}]`, left to right. */
    grouped: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    /** Dragging cards: off while the list is filtered, the order shown being partial. */
    draggable: { type: Boolean, default: false },
    outcomeWindowDays: { type: Number, default: 30 },
    actionsFor: { type: Function, required: true },
    pageOf: { type: Function, required: true },
    lastInteractionOf: { type: Function, required: true },
});

const emit = defineEmits(["move", "edit-stage", "delete-stage", "add-stage", "reorder-stages"]);

const { t } = useI18n();
const { formatMoney } = useMoneyFormat();

function totalOf(cards) {
    return stageTotals(cards)
        .map((total) => formatMoney(total.cents, total.currency))
        .join(" · ");
}
</script>

<template>
    <div class="overflow-x-auto pb-2 scrollbar-thin" data-prospect-board>
        <div class="flex items-start gap-3">
            <VueDraggable
                :model-value="grouped"
                handle=".stage-drag-handle"
                :animation="150"
                :disabled="!editable"
                class="flex items-start gap-3"
                v-on:update:model-value="emit('reorder-stages', $event.map((group) => group.stage.id))"
            >
                <section
                    v-for="group in grouped"
                    :key="group.stage.id"
                    class="flex w-72 shrink-0 flex-col overflow-hidden rounded-xl border border-line bg-surface-2/40"
                    :data-pipeline-stage="group.stage.id"
                    :data-pipeline-role="group.stage.role || undefined"
                >
                    <div
                        v-if="group.stage.colourSlot"
                        class="h-1 w-full"
                        :style="{ backgroundColor: `var(--chart-cat-${group.stage.colourSlot})` }"
                    />
                    <header class="border-b border-line/40 px-3 py-2">
                        <div class="flex items-center gap-2">
                            <GripVertical
                                v-if="editable"
                                class="stage-drag-handle h-3.5 w-3.5 shrink-0 cursor-grab text-muted"
                                :stroke-width="2"
                                :aria-label="t('suite.studio.pipeline.move_stage')"
                            />
                            <h3 class="m-0 min-w-0 flex-1 truncate text-sm font-medium text-primary">{{ group.stage.name }}</h3>
                            <span class="text-xs tabular-nums text-muted">{{ group.cards.length }}</span>
                            <template v-if="editable">
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted transition-colors hover:text-primary"
                                    :aria-label="t('suite.studio.pipeline.edit_stage')"
                                    v-on:click="emit('edit-stage', group.stage)"
                                >
                                    <Pencil class="h-3 w-3" :stroke-width="2" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded p-1 text-muted transition-colors hover:text-danger"
                                    :aria-label="t('shared.common.delete')"
                                    v-on:click="emit('delete-stage', group.stage)"
                                >
                                    <Trash2 class="h-3 w-3" :stroke-width="2" />
                                </button>
                            </template>
                        </div>
                        <!-- What the stage weighs, and for an outcome how far
                             back it shows. -->
                        <p v-if="totalOf(group.cards) || group.stage.role" class="m-0 mt-0.5 flex flex-wrap gap-x-2 text-xs text-muted">
                            <span v-if="totalOf(group.cards)" class="font-medium tabular-nums text-secondary" data-stage-total>{{ totalOf(group.cards) }}</span>
                            <span v-if="group.stage.role">{{ t("suite.studio.pipeline.outcome_window", { days: outcomeWindowDays }) }}</span>
                        </p>
                    </header>

                    <VueDraggable
                        :model-value="group.cards"
                        group="prospects"
                        handle=".card-drag-handle"
                        :animation="150"
                        :disabled="!draggable"
                        class="flex min-h-16 flex-col gap-2 p-2"
                        v-on:update:model-value="emit('move', group.stage.id, $event)"
                    >
                        <ProspectCard
                            v-for="card in group.cards"
                            :key="card.id"
                            :customer="card"
                            :href="pageOf(card)"
                            :actions="actionsFor(card)"
                            :last-interaction="lastInteractionOf(card)"
                            :draggable="draggable && 'prospect' === card.status"
                        />
                    </VueDraggable>

                    <p v-if="!group.cards.length" class="m-0 px-3 pb-3 text-xs text-muted">
                        {{ t("suite.studio.pipeline.empty_stage") }}
                    </p>
                </section>
            </VueDraggable>

            <button
                v-if="editable"
                type="button"
                class="flex w-56 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-dashed border-line px-3 py-3 text-sm text-muted transition-colors hover:border-line-strong hover:text-primary"
                v-on:click="emit('add-stage')"
            >
                <Plus class="h-4 w-4" :stroke-width="2" />
                {{ t("suite.studio.pipeline.add_stage") }}
            </button>
        </div>
    </div>
</template>
