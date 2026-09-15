<script setup>
/**
 * The board of one space: the steps, left to right, and the content on them.
 *
 * **The columns are a view and own nothing.** A card's step is a column on the
 * card itself, so dragging one writes a field and not a membership. The
 * calendar that comes next reads the same rows through their date, which is why
 * neither view holds a list of its own.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { VueDraggable } from "vue-draggable-plus";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useSpaceBoard } from "./composables/useSpaceBoard.js";
import SpaceContentCard from "./components/SpaceContentCard.vue";
import SpaceContentItemFields from "./components/SpaceContentItemFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppColourSlotPicker from "@/shared/components/form/picker/AppColourSlotPicker.vue";
import {
    Columns3,
    FileText,
    Pencil,
    Plus,
    Save,
    Trash2,
    X,
} from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    space: { type: Object, required: true },
    columns: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    itemCreatePath: { type: String, required: true },
    itemUpdatePath: { type: String, required: true },
    itemDeletePath: { type: String, required: true },
    itemReorderPath: { type: String, required: true },
    columnCreatePath: { type: String, required: true },
    columnUpdatePath: { type: String, required: true },
    columnDeletePath: { type: String, required: true },
    columnReorderPath: { type: String, required: true },
});

const {
    grouped,
    isEmpty,
    columnOptions,
    showItemForm,
    editingItem,
    itemForm,
    itemErrors,
    itemLoading,
    openItemCreate,
    openItemEdit,
    submitItem,
    pendingItemDelete,
    itemDeleteLoading,
    confirmItemDelete,
    deleteItem,
    reorderItems,
    showColumnForm,
    editingColumn,
    columnForm,
    columnErrors,
    columnLoading,
    openColumnCreate,
    openColumnEdit,
    submitColumn,
    pendingColumnDelete,
    columnDeleteLoading,
    confirmColumnDelete,
    deleteColumn,
} = useSpaceBoard(props.columns, props.items, {
    itemCreatePath: props.itemCreatePath,
    itemUpdatePath: props.itemUpdatePath,
    itemDeletePath: props.itemDeletePath,
    itemReorderPath: props.itemReorderPath,
    columnCreatePath: props.columnCreatePath,
    columnUpdatePath: props.columnUpdatePath,
    columnDeletePath: props.columnDeletePath,
    columnReorderPath: props.columnReorderPath,
});

const editable = computed(() => can("studio.spaces.edit"));

function cardActions(item) {
    if (!editable.value) return [];

    return [
        {
            key: "edit",
            icon: Pencil,
            title: t("shared.common.edit"),
            onSelect: () => openItemEdit(item),
        },
        {
            key: "delete",
            icon: Trash2,
            danger: true,
            title: t("shared.common.delete"),
            onSelect: () => confirmItemDelete(item),
        },
    ];
}

/** Handed the column's cards in their new order by the drag library. */
function onCardsChanged(columnId, cards) {
    if (!editable.value) return;

    reorderItems(columnId, cards);
}
</script>

<template>
    <div class="space-y-4">
        <!-- The customer is named by the shell above, once. It used to be
             repeated here, which is how a header and its page end up
             disagreeing about a client's name after a rename. -->
        <div v-if="editable" class="flex justify-end">
            <AppButton variant="ghost" size="sm" v-on:click="openColumnCreate">
                <Columns3 class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_content.add_column") }}
            </AppButton>
        </div>

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
                    class="flex w-72 shrink-0 flex-col overflow-hidden rounded-xl border border-line/60 bg-surface-2/40"
                >
                    <!-- A rule across the top rather than a dot beside the
                         name: the column is what is being identified, and a
                         full-width bar is read without being looked for. A
                         step with no colour gets no bar, which is the point of
                         letting it have none. -->
                    <div
                        v-if="group.column.colourSlot"
                        class="h-1 w-full"
                        :style="{
                            backgroundColor: `var(--chart-cat-${group.column.colourSlot})`,
                        }"
                    />
                    <header
                        class="flex items-center gap-2 border-b border-line/40 px-3 py-2"
                    >
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
                                v-on:click="openColumnEdit(group.column)"
                            >
                                <Pencil class="h-3 w-3" :stroke-width="2" />
                            </button>
                            <button
                                type="button"
                                class="rounded p-1 text-muted transition-colors hover:text-red-500"
                                :aria-label="t('shared.common.delete')"
                                v-on:click="confirmColumnDelete(group.column)"
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
                        v-on:update:model-value="onCardsChanged(group.column.id, $event)"
                    >
                        <SpaceContentCard
                            v-for="card in group.cards"
                            :key="card.id"
                            :item="card"
                            :actions="cardActions(card)"
                        />
                    </VueDraggable>

                    <p
                        v-if="!group.cards.length"
                        class="px-3 pb-2 text-xs text-muted"
                    >
                        {{ t("backend.studio.space_content.empty_column") }}
                    </p>

                    <button
                        v-if="editable"
                        type="button"
                        class="m-2 mt-0 flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-xs text-muted transition-colors hover:bg-surface hover:text-primary"
                        v-on:click="openItemCreate({ columnId: group.column.id })"
                    >
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("backend.studio.space_content.add_item") }}
                    </button>
                </section>
            </div>
        </div>

        <AppModal
            :show="showItemForm"
            max-width="2xl"
            :title="
                editingItem
                    ? t('backend.studio.space_content.edit_item', {
                        title: editingItem.title,
                    })
                    : t('backend.studio.space_content.create_item')
            "
            :icon="FileText"
            :closeable="false"
            v-on:close="showItemForm = false"
        >
            <form v-on:submit.prevent="submitItem">
                <SpaceContentItemFields
                    v-model="itemForm"
                    :errors="itemErrors"
                    :column-options="columnOptions"
                    :timezone="space.timezone"
                    :approval="editingItem?.approval ?? 'pending'"
                    :approval-note="editingItem?.approvalNote ?? ''"
                    :approval-by="editingItem?.approvalBy ?? ''"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showItemForm = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="itemLoading"
                        v-on:click="submitItem"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showColumnForm"
            max-width="sm"
            :title="
                editingColumn
                    ? t('backend.studio.space_content.edit_column')
                    : t('backend.studio.space_content.create_column')
            "
            :icon="Columns3"
            :closeable="false"
            v-on:close="showColumnForm = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitColumn">
                <AppInput
                    :model-value="columnForm.name"
                    :label="t('backend.studio.space_content.column_name')"
                    :placeholder="t('backend.studio.space_content.column_name_placeholder')"
                    :error="columnErrors.name"
                    required
                    v-on:update:model-value="columnForm = { ...columnForm, name: $event }"
                />
                <AppColourSlotPicker
                    :model-value="columnForm.colourSlot"
                    clearable
                    :label="t('backend.studio.space_content.column_colour')"
                    :hint="t('backend.studio.space_content.column_colour_hint')"
                    :error="columnErrors.colourSlot"
                    v-on:update:model-value="columnForm = { ...columnForm, colourSlot: $event }"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showColumnForm = false">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="primary"
                        size="md"
                        :loading="columnLoading"
                        v-on:click="submitColumn"
                    >
                        <Save class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.save") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingItemDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingItemDelete = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.space_content.delete_item_confirm", {
                        title: pendingItemDelete?.title ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.space_content.delete_item_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingItemDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="itemDeleteLoading"
                        v-on:click="deleteItem"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingColumnDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingColumnDelete = null"
        >
            <p class="text-sm text-primary">
                {{
                    t("backend.studio.space_content.delete_column_confirm", {
                        name: pendingColumnDelete?.name ?? "",
                    })
                }}
            </p>
            <p class="text-sm text-secondary">
                {{ t("backend.studio.space_content.delete_column_warning") }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingColumnDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton
                        variant="danger"
                        size="md"
                        :loading="columnDeleteLoading"
                        v-on:click="deleteColumn"
                    >
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                        {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
