<script setup>
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useDocumentTagsForm } from "./composables/useDocumentTagsForm.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppColorPicker from "@/shared/components/form/picker/AppColorPicker.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Plus, Pencil, Trash2, Save, X, Tag } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();
const props = defineProps({
    tags: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
});

const {
    items,
    search: tagSearch,
    filteredItems,
    showCreate, newTag, createErrors, createLoading, openCreate, submitCreate,
    showEdit, editingTag, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentTagsForm(props.tags, props.createPath, props.updatePath, props.deletePath);

const actionsFor = useEditDeleteActions({
    can,
    editPermission: "ged.tags.manage",
    deletePermission: "ged.tags.manage",
    openEdit,
    confirmDelete,
    editDescription: "backend.ged.tags.row_actions.edit_description",
    deleteDescription: "backend.ged.tags.row_actions.delete_description",
});
</script>

<template>
    <div class="space-y-4">
        <AppListToolbar>
            <AppSearchInput v-model="tagSearch" :placeholder="t('backend.ged.tags.search_placeholder')" />
            <template #actions>
                <AppButton
                    v-if="can('ged.tags.manage')"
                    variant="primary"
                    size="md"
                    class="w-full sm:w-auto"
                    v-on:click="openCreate"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.ged.tags.add") }}
                </AppButton>
            </template>
        </AppListToolbar>

        <!-- Mobile cards -->
        <div class="sm:hidden space-y-2">
            <AppNoData v-if="!filteredItems.length" :message="t('backend.ged.tags.empty')" />
            <div v-for="tag in filteredItems" :key="tag.id" class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm">
                <div class="flex items-center gap-3 px-4 py-3">
                    <span v-if="tag.color" class="inline-block w-3 h-3 rounded-full shrink-0" :style="{ backgroundColor: tag.color }" />
                    <div class="min-w-0">
                        <p class="font-medium text-primary text-sm truncate">{{ tag.name }}</p>
                        <p v-if="tag.color" class="text-xs text-muted font-mono mt-0.5">{{ tag.color }}</p>
                    </div>
                </div>
                <div class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40">
                    <AppRowActions :actions="actionsFor(tag)" :label="tag.name ?? tag.label ?? ''" />
                </div>
            </div>
        </div>

        <!-- Desktop table -->
        <div class="hidden sm:block bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-surface-2/50 border-b border-line/40">
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.ged.tags.name") }}</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("backend.ged.tags.color") }}</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line/40">
                    <tr v-for="tag in filteredItems" :key="tag.id" class="group hover:bg-surface-2/40 transition-colors">
                        <td class="px-6 py-3 font-medium text-primary flex items-center gap-2">
                            <span v-if="tag.color" class="inline-block w-3 h-3 rounded-full" :style="{ backgroundColor: tag.color }" />
                            {{ tag.name }}
                        </td>
                        <td class="px-6 py-3 text-muted font-mono text-xs hidden md:table-cell">{{ tag.color ?? '—' }}</td>
                        <td class="px-6 py-3">
                            <div class="flex items-center justify-end gap-0.5">
                                <AppRowActions :actions="actionsFor(tag)" :label="tag.name ?? tag.label ?? ''" />
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!filteredItems.length">
                        <td :colspan="3"><AppNoData :message="t('backend.ged.tags.empty')" /></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <AppModal
            :show="showCreate"
            :title="t('backend.ged.tags.create')"
            :icon="Tag"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput
                    v-model="newTag.name"
                    :label="t('backend.ged.tags.name')"
                    :placeholder="t('backend.ged.tags.name_placeholder')"
                    :error="createErrors.name"
                    required
                />
                <AppColorPicker
                    v-model="newTag.color"
                    :label="t('backend.ged.tags.color')"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showCreate = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="createLoading" v-on:click="submitCreate"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showEdit"
            :title="t('backend.ged.tags.edit', { name: editingTag?.name ?? '' })"
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <AppInput
                    v-model="editForm.name"
                    :label="t('backend.ged.tags.name')"
                    :placeholder="t('backend.ged.tags.name_placeholder')"
                    :error="editErrors.name"
                    required
                />
                <AppColorPicker
                    v-model="editForm.color"
                    :label="t('backend.ged.tags.color')"
                />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
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
            <p class="text-sm text-primary">{{ t("backend.ged.tags.delete_confirm", { name: pendingDelete?.name ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("backend.ged.tags.delete_warning") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
