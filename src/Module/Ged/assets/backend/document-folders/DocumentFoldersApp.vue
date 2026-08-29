<script setup>
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useDocumentFoldersForm } from "./composables/useDocumentFoldersForm.js";
import { useDocumentFolderTree } from "./composables/useDocumentFolderTree.js";
import { useDocumentFolderDragDrop } from "./composables/useDocumentFolderDragDrop.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Plus, Pencil, Trash2, Save, X, Folder, ChevronRight, GripVertical } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();
const props = defineProps({
    folders: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    movePath: { type: String, default: "" },
    reorderPath: { type: String, default: "" },
});

const {
    items, parentOptions,
    showCreate, newFolder, createErrors, createLoading, openCreate, submitCreate,
    showEdit, editingFolder, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentFoldersForm(props.folders, props.createPath, props.updatePath, props.deletePath);

const actionsFor = useEditDeleteActions({
    can,
    editPermission: "ged.folders.manage",
    deletePermission: "ged.folders.manage",
    openEdit,
    confirmDelete,
    editDescription: "backend.ged.folders.row_actions.edit_description",
    deleteDescription: "backend.ged.folders.row_actions.delete_description",
});

const { flatTree, filteredTree, search: folderSearch, collapsedIds, toggleCollapse } = useDocumentFolderTree(items);

const { draggingId, dropTarget, onDragStart, onDragOver, onDragLeave, onDragEnd, onDrop } =
    useDocumentFolderDragDrop(props.movePath, props.reorderPath, (updatedFolders) => {
        items.value = updatedFolders;
    });
</script>

<template>
    <div class="space-y-4">
        <AppListToolbar>
            <AppSearchInput
                v-model="folderSearch"
                :placeholder="t('backend.ged.folders.search_placeholder')"
            />
            <template #actions>
                <AppButton
                    v-if="can('ged.folders.manage')"
                    variant="primary"
                    size="md"
                    class="w-full sm:w-auto"
                    v-on:click="openCreate"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.ged.folders.add") }}
                </AppButton>
            </template>
        </AppListToolbar>

        <div class="bg-surface border border-line rounded-lg overflow-hidden">
            <div v-if="!items?.length" class="px-6 py-4">
                <AppNoData :message="t('backend.ged.folders.empty')" />
            </div>
            <div v-else class="divide-y divide-line/40">
                <div
                    v-for="node in filteredTree"
                    :key="node.id"
                    draggable="true"
                    class="group relative flex items-center gap-1.5 pr-3 transition-colors cursor-grab active:cursor-grabbing select-none"
                    :class="{
                        'opacity-40': draggingId === node.id,
                        'bg-surface-2/40': dropTarget?.id === node.id && dropTarget?.zone === 'into',
                        'hover:bg-surface-2/40': draggingId !== node.id,
                    }"
                    :style="{ paddingLeft: `${0.75 + node.depth * 1.25}rem` }"
                    v-on:dragstart="onDragStart($event, node)"
                    v-on:dragover="onDragOver($event, node)"
                    v-on:dragleave="onDragLeave"
                    v-on:dragend="onDragEnd"
                    v-on:drop="onDrop($event, node, flatTree)"
                >
                    <!-- Drop indicator lines -->
                    <div
                        v-if="dropTarget?.id === node.id && dropTarget?.zone === 'before'"
                        class="absolute top-0 left-0 right-0 h-0.5 bg-accent rounded-full pointer-events-none"
                    />
                    <div
                        v-if="dropTarget?.id === node.id && dropTarget?.zone === 'after'"
                        class="absolute bottom-0 left-0 right-0 h-0.5 bg-accent rounded-full pointer-events-none"
                    />

                    <!-- Drag handle -->
                    <GripVertical class="w-3.5 h-3.5 text-muted/40 group-hover:text-muted shrink-0 transition-colors" :stroke-width="2" />

                    <!-- Collapse toggle -->
                    <AppIconButton
                        v-if="node.childCount"
                        color="default"
                        class="shrink-0"
                        v-on:click.stop="toggleCollapse(node.id)"
                    >
                        <ChevronRight
                            class="w-3.5 h-3.5 transition-transform duration-150"
                            :class="collapsedIds.has(node.id) ? '' : 'rotate-90'"
                            :stroke-width="2"
                        />
                    </AppIconButton>
                    <span v-else class="w-4 shrink-0" />

                    <Folder
                        class="w-4 h-4 shrink-0 transition-colors"
                        :class="dropTarget?.id === node.id && dropTarget?.zone === 'into' ? 'text-accent' : 'text-muted'"
                        :stroke-width="1.5"
                    />

                    <span class="flex-1 py-3 text-sm font-medium text-primary truncate">{{ node.name }}</span>

                    <span v-if="node.childCount" class="text-xs text-muted tabular-nums shrink-0">{{ node.childCount }}</span>

                    <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity ml-2">
                        <AppRowActions :actions="actionsFor(node)" :label="node.name ?? ''" />
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Outside the `v-else` on purpose. These modals used to live inside it,
         so with nothing created yet the branch was not rendered and neither
         were they: the empty state's button set the flag and nothing existed
         to react. The first item could never be created, and only the first -
         once one existed the branch rendered and the button worked. -->
    <AppModal
        :show="showCreate"
        :title="t('backend.ged.folders.create')"
        :icon="Folder"
        :closeable="false"
        v-on:close="showCreate = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submitCreate">
            <AppInput
                v-model="newFolder.name"
                :label="t('backend.ged.folders.name')"
                :placeholder="t('backend.ged.folders.name_placeholder')"
                :error="createErrors.name"
                required
            />
            <AppMultiselect
                v-model="newFolder.parentId"
                :label="t('backend.ged.folders.parent')"
                :options="parentOptions"
                :allow-empty="true"
            />
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" type="button" v-on:click="showCreate = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>

    <AppModal
        :show="showEdit"
        :title="t('backend.ged.folders.edit', { name: editingFolder?.name ?? '' })"
        :icon="Pencil"
        :closeable="false"
        v-on:close="showEdit = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submitEdit">
            <AppInput
                v-model="editForm.name"
                :label="t('backend.ged.folders.name')"
                :placeholder="t('backend.ged.folders.name_placeholder')"
                :error="editErrors.name"
                required
            />
            <AppMultiselect
                v-model="editForm.parentId"
                :label="t('backend.ged.folders.parent')"
                :options="parentOptions.filter((opt) => opt.value !== editingFolder?.id)"
                :allow-empty="true"
            />
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" type="button" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>

    <AppModal
        :show="!!pendingDelete"
        max-width="sm"
        :closeable="false"
        :title="t('shared.common.delete')"
        :icon="Trash2"
        v-on:close="pendingDelete = null"
    >
        <p class="text-sm text-primary">{{ t("backend.ged.folders.delete_confirm", { name: pendingDelete?.name ?? "" }) }}</p>
        <p class="text-sm text-secondary">{{ t("backend.ged.folders.delete_warning") }}</p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
