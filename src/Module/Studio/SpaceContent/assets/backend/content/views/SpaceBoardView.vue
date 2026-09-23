<script setup>
/**
 * The content as a board: one column per step, cards dragged between them.
 *
 * Presentational. It owns no data and no write - everything arrives as props
 * and leaves as an event, which is what lets three views of the same rows exist
 * without three copies of the state behind them.
 */
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import SpaceContentCard from "../components/SpaceContentCard.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Pencil, Plus, Trash2 } from "lucide-vue-next";

defineProps({
    grouped: { type: Array, default: () => [] },
    editable: { type: Boolean, default: false },
    actionsFor: { type: Function, required: true },
    filesOf: { type: Function, required: true },
    isEmpty: { type: Boolean, default: false },
});

const emit = defineEmits([
    "open-item",
    "reorder",
    "add-item",
    "edit-column",
    "delete-column",
]);

const { t } = useI18n();
</script>

<template>
    <div>
        <AppNoData
            v-if="isEmpty && !grouped.length"
            :message="t('backend.studio.space_content.empty_board')"
        />

        <!-- One horizontal track, each column a fixed width: a board that lets
             its columns shrink to fit stops being scannable at the fourth one,
             and a client's steps are theirs to add. -->
        <div class="overflow-x-auto pb-2 scrollbar-thin">
            <div class="flex items-start gap-3">
                <section
                    v-for="group in grouped"
                    :key="group.column.id"
                    class="flex w-72 shrink-0 flex-col overflow-hidden rounded-xl border border-line bg-surface-2/40"
                >
                    <!-- A rule across the top rather than a dot beside the
                         name: the column is what is being identified, and a
                         full-width bar is read without being looked for. -->
                    <div
                        v-if="group.column.colourSlot"
                        class="h-1 w-full"
                        :style="{
                            backgroundColor: `var(--chart-cat-${group.column.colourSlot})`,
                        }"
                    />
                    <header class="flex items-center gap-2 border-b border-line/40 px-3 py-2">
                        <h3 class="min-w-0 flex-1 truncate text-sm font-medium text-primary">
                            {{ group.column.name }}
                        </h3>
                        <span class="text-xs tabular-nums text-muted">
                            {{ group.cards.length }}
                        </span>
                        <template v-if="editable">
                            <button
                                type="button"
                                class="rounded p-1 text-muted transition-colors hover:text-primary"
                                :aria-label="t('backend.studio.space_content.edit_column')"
                                v-on:click="emit('edit-column', group.column)"
                            >
                                <Pencil class="h-3 w-3" :stroke-width="2" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-muted transition-colors hover:text-red-500"
                                :aria-label="t('shared.common.delete')"
                                v-on:click="emit('delete-column', group.column)"
                            >
                                <Trash2 class="h-3 w-3" :stroke-width="2" />
                            </button>
                        </template>
                    </header>

                    <VueDraggable
                        :model-value="group.cards"
                        group="space-content"
                        handle=".card-drag-handle"
                        :animation="150"
                        :disabled="!editable"
                        class="flex min-h-16 flex-col gap-2 p-2"
                        v-on:update:model-value="emit('reorder', group.column.id, $event)"
                    >
                        <SpaceContentCard
                            v-for="card in group.cards"
                            :key="card.id"
                            :item="card"
                            :actions="actionsFor(card)"
                            :files="filesOf(card)"
                            v-on:open="emit('open-item', $event)"
                        />
                    </VueDraggable>

                    <p v-if="!group.cards.length" class="px-3 pb-2 text-xs text-muted">
                        {{ t("backend.studio.space_content.empty_column") }}
                    </p>

                    <button
                        v-if="editable"
                        type="button"
                        class="m-2 mt-0 flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-xs text-muted transition-colors hover:bg-surface hover:text-primary"
                        v-on:click="emit('add-item', group.column.id)"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_content.add_item") }}
                    </button>
                </section>
            </div>
        </div>
    </div>
</template>
