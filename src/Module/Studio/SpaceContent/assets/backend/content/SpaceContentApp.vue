<script setup>
/**
 * A space's content, in whichever of three views the reader prefers.
 *
 * **The views are a preference, not a destination.** The board, the list and
 * the month show the same rows and differ only in how somebody likes to read
 * them, so the choice lives with the person and not in the address: it is
 * remembered, it costs no request, and it is the same in every space they open.
 * What is on screen - which space - stays in the URL.
 *
 * The list exists because a board is not everybody's way of thinking, and
 * because it is the only one of the three that fits on a phone without
 * scrolling sideways.
 *
 * This component owns the state and the writes; the three views own nothing
 * and hand everything back as events. That is what lets a card edited in one
 * of them be right in the other two.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { usePersistedChoice } from "@/shared/composables/usePersistedChoice.js";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useSpaceContent } from "./composables/useSpaceContent.js";
import SpaceBoardView from "./views/SpaceBoardView.vue";
import SpaceListView from "./views/SpaceListView.vue";
import SpaceCalendarView from "./views/SpaceCalendarView.vue";
import SpaceContentItemFields from "./components/SpaceContentItemFields.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppColourSlotPicker from "@/shared/components/form/picker/AppColourSlotPicker.vue";
import {
    CalendarDays,
    Columns3,
    FileText,
    List,
    Pencil,
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
    comments: { type: Object, default: () => ({}) },
    attachments: { type: Object, default: () => ({}) },
    itemCreatePath: { type: String, required: true },
    itemUpdatePath: { type: String, required: true },
    itemDeletePath: { type: String, required: true },
    itemReorderPath: { type: String, required: true },
    schedulePath: { type: String, required: true },
    commentPostPath: { type: String, required: true },
    commentDeletePath: { type: String, required: true },
    attachmentUploadPath: { type: String, required: true },
    attachmentAttachPath: { type: String, required: true },
    attachmentDetachPath: { type: String, required: true },
    columnCreatePath: { type: String, required: true },
    columnUpdatePath: { type: String, required: true },
    columnDeletePath: { type: String, required: true },
    columnReorderPath: { type: String, required: true },
});

const VIEWS = [
    { key: "board", labelKey: "backend.studio.space_content.view_board", icon: Columns3 },
    { key: "list", labelKey: "backend.studio.space_content.view_list", icon: List },
    { key: "calendar", labelKey: "backend.studio.space_content.view_calendar", icon: CalendarDays },
];

/**
 * One key for every space, deliberately.
 *
 * Somebody who does not think in columns does not think in columns for one
 * client and in columns for the next. A per-space key would make them choose
 * again on every space they open, which is the thing this exists to stop.
 */
const { choice: view } = usePersistedChoice(
    "studio.space_content.view",
    "board",
    VIEWS.map((entry) => entry.key),
);

const {
    isEmpty,
    grouped,
    unscheduled,
    events,
    cellsFor,
    columnOptions,
    columnsById,
    reorderItems,
    moveEvent,
    openEvent,
    addOn,
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
    threadOf,
    commentLoading,
    postComment,
    deleteComment,
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
    filesOf,
    attachmentLoading,
    upload,
    pick,
    remove,
} = useSpaceContent(
    {
        columns: props.columns,
        items: props.items,
        comments: props.comments,
        attachments: props.attachments,
    },
    {
        itemCreatePath: props.itemCreatePath,
        itemUpdatePath: props.itemUpdatePath,
        itemDeletePath: props.itemDeletePath,
        itemReorderPath: props.itemReorderPath,
        schedulePath: props.schedulePath,
        commentPostPath: props.commentPostPath,
        commentDeletePath: props.commentDeletePath,
        attachmentUploadPath: props.attachmentUploadPath,
        attachmentAttachPath: props.attachmentAttachPath,
        attachmentDetachPath: props.attachmentDetachPath,
        columnCreatePath: props.columnCreatePath,
        columnUpdatePath: props.columnUpdatePath,
        columnDeletePath: props.columnDeletePath,
        columnReorderPath: props.columnReorderPath,
        colourSlot: props.space.colourSlot,
    },
);

const editable = computed(() => can("studio.spaces.edit"));

const actionsFor = useEditDeleteActions({
    can,
    editPermission: "studio.spaces.edit",
    deletePermission: "studio.spaces.edit",
    openEdit: openItemEdit,
    confirmDelete: confirmItemDelete,
    editDescription: "backend.studio.space_content.row_actions.edit_description",
    deleteDescription: "backend.studio.space_content.row_actions.delete_description",
});
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <!-- Segmented rather than a select: three choices are worth showing
                 at once, and the one in use is the answer to "why does this
                 look different from yesterday". -->
            <div
                class="flex items-center gap-0.5 rounded-lg border border-line/60 bg-surface-2/40 p-0.5"
                role="group"
                :aria-label="t('backend.studio.space_content.view_label')"
            >
                <button
                    v-for="entry in VIEWS"
                    :key="entry.key"
                    type="button"
                    class="flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm transition-colors"
                    :class="
                        view === entry.key
                            ? 'bg-surface font-medium text-primary shadow-sm'
                            : 'text-muted hover:text-primary'
                    "
                    :aria-pressed="view === entry.key"
                    v-on:click="view = entry.key"
                >
                    <component :is="entry.icon" class="h-3.5 w-3.5" :stroke-width="2" />
                    {{ t(entry.labelKey) }}
                </button>
            </div>

            <AppButton
                v-if="editable && view !== 'calendar'"
                variant="ghost"
                size="sm"
                v-on:click="openColumnCreate"
            >
                <Columns3 class="h-3.5 w-3.5" :stroke-width="2" />
                {{ t("backend.studio.space_content.add_column") }}
            </AppButton>
        </div>

        <SpaceBoardView
            v-if="view === 'board'"
            :grouped="grouped"
            :editable="editable"
            :actions-for="actionsFor"
            :files-of="filesOf"
            :is-empty="isEmpty"
            v-on:reorder="reorderItems"
            v-on:add-item="openItemCreate({ columnId: $event })"
            v-on:edit-column="openColumnEdit"
            v-on:delete-column="confirmColumnDelete"
        />

        <SpaceListView
            v-else-if="view === 'list'"
            :grouped="grouped"
            :editable="editable"
            :actions-for="actionsFor"
            :files-of="filesOf"
            :is-empty="isEmpty"
            v-on:add-item="openItemCreate({ columnId: $event })"
            v-on:open-item="openItemEdit"
        />

        <SpaceCalendarView
            v-else
            :events="events"
            :unscheduled="unscheduled"
            :columns-by-id="columnsById"
            :cells-for="cellsFor"
            v-on:open-event="openEvent"
            v-on:move-event="moveEvent"
            v-on:add-on="addOn"
            v-on:open-item="openItemEdit"
        />

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
                    :approval-by="editingItem?.approvalBy ?? ''"
                    :comments="threadOf(editingItem)"
                    :comment-loading="commentLoading"
                    :can-discuss="!!editingItem"
                    :attachments="filesOf(editingItem)"
                    :attachment-loading="attachmentLoading"
                    v-on:post-comment="postComment(editingItem, $event)"
                    v-on:delete-comment="deleteComment"
                    v-on:upload-attachment="upload(editingItem, $event)"
                    v-on:pick-attachment="pick(editingItem)"
                    v-on:remove-attachment="remove"
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
            :icon="editingColumn ? Pencil : Columns3"
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
