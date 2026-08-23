<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { useListPage } from "@/shared/composables/list/useListPage.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useDocumentCategoriesForm } from "./composables/useDocumentCategoriesForm.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import AppListToolbar from "@/shared/components/list/AppListToolbar.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import { Plus, Pencil, Trash2, Save, X, Tag } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();
const props = defineProps({
    categories: { type: Object, default: () => ({}) },
    search: { type: String, default: "" },
    createPath: { type: String, required: true },
    updatePath: { type: String, required: true },
    deletePath: { type: String, required: true },
    listPath: { type: String, required: true },
    // Client extension point. A wrapper in an aurora-client project passes
    // `{ color: { default: "", fromEntity: (cat) => cat.color ?? "" } }` and
    // fills the three scoped slots below; this component stays untouched, so
    // an aurora-core update never conflicts with it.
    extraFields: { type: Object, default: () => ({}) },
});

const { items, loading, page, totalPages, search: searchInput, onSearch, goToPage, reload: reset } = useListPage(
    props.listPath, { initialSearch: props.search, initialData: props.categories },
);

const {
    showCreate, newCategory, createErrors, createLoading, openCreate, submitCreate,
    showEdit, editingCategory, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
} = useDocumentCategoriesForm(props.createPath, props.updatePath, props.deletePath, reset, props.extraFields);

const actionsFor = useEditDeleteActions({
    can,
    editPermission: "ged.categories.edit",
    deletePermission: "ged.categories.delete",
    openEdit,
    confirmDelete,
    editDescription: "backend.ged.categories.row_actions.edit_description",
    deleteDescription: "backend.ged.categories.row_actions.delete_description",
});

// Name + slug + actions, plus whatever the client added - otherwise the empty
// row stops spanning the table the moment an extra column exists.
const columnCount = computed(() => 3 + Object.keys(props.extraFields).length);
</script>

<template>
    <div class="space-y-4">
        <AppListToolbar>
            <AppSearchInput v-model="searchInput" :placeholder="t('backend.ged.categories.search_placeholder')" v-on:search="onSearch" />
            <template #actions>
                <AppButton
                    v-if="can('ged.categories.create')"
                    variant="primary"
                    size="md"
                    class="w-full sm:w-auto"
                    v-on:click="openCreate"
                >
                    <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.ged.categories.add") }}
                </AppButton>
            </template>
        </AppListToolbar>

        <div class="relative space-y-4">
            <!-- Mobile cards -->
            <div class="sm:hidden space-y-2">
                <AppNoData v-if="!items?.length" :message="t('backend.ged.categories.empty')" />
                <div v-for="cat in items" :key="cat.id" class="bg-surface border border-line/60 rounded-xl overflow-hidden shadow-sm">
                    <div class="px-4 py-3">
                        <p class="font-medium text-primary text-sm">{{ cat.name }}</p>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ cat.slug }}</p>
                    </div>
                    <div class="flex justify-end px-3 py-2 border-t border-line/40 bg-surface-2/40">
                        <AppRowActions :actions="actionsFor(cat)" :label="cat.name ?? cat.label ?? ''" />
                    </div>
                </div>
            </div>

            <!-- Desktop table -->
            <div class="hidden sm:block bg-surface border border-line rounded-lg overflow-x-auto scrollbar-thin">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t("backend.ged.categories.name") }}</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t("backend.ged.categories.slug") }}</th>
                            <slot name="extra-headers" />
                            <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t("shared.common.actions") }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40">
                        <tr v-for="cat in items" :key="cat.id" class="group hover:bg-surface-2/40 transition-colors">
                            <td class="px-6 py-3 font-medium text-primary">{{ cat.name }}</td>
                            <td class="px-6 py-3 text-muted font-mono text-xs hidden md:table-cell">{{ cat.slug }}</td>
                            <slot name="extra-cells" :category="cat" />
                            <td class="px-6 py-3">
                                <div class="flex items-center justify-end gap-0.5">
                                    <AppRowActions :actions="actionsFor(cat)" :label="cat.name ?? cat.label ?? ''" />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!items?.length">
                            <td :colspan="columnCount"><AppNoData :message="t('backend.ged.categories.empty')" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <AppPagination v-if="totalPages > 1" :page="page" :total-pages="totalPages" v-on:go-to-page="goToPage" />
            <AppLoader :active="loading" />
        </div>

        <AppModal
            :show="showCreate"
            :title="t('backend.ged.categories.create')"
            :icon="Tag"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput
                    v-model="newCategory.name"
                    :label="t('backend.ged.categories.name')"
                    :placeholder="t('backend.ged.categories.name_placeholder')"
                    :error="createErrors.name"
                    required
                />
                <AppInput v-model="newCategory.description" :label="t('backend.ged.categories.description')" :placeholder="t('backend.ged.categories.description_placeholder')" />
                <slot name="extra-form-fields" :form="newCategory" :errors="createErrors" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="showCreate = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="createLoading"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="showEdit"
            :title="t('backend.ged.categories.edit', { name: editingCategory?.name ?? '' })"
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <AppInput
                    v-model="editForm.name"
                    :label="t('backend.ged.categories.name')"
                    :placeholder="t('backend.ged.categories.name_placeholder')"
                    :error="editErrors.name"
                    required
                />
                <AppInput v-model="editForm.description" :label="t('backend.ged.categories.description')" :placeholder="t('backend.ged.categories.description_placeholder')" />
                <slot name="extra-form-fields" :form="editForm" :errors="editErrors" />
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" type="button" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" type="submit" :loading="editLoading"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
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
            <p class="text-sm text-primary">{{ t("backend.ged.categories.delete_confirm", { name: pendingDelete?.name ?? "" }) }}</p>
            <p class="text-sm text-secondary">{{ t("backend.ged.categories.delete_warning") }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
