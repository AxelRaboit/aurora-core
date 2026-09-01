<script setup>
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { usePostTypesForm } from "./composables/usePostTypesForm.js";
import { usePostTypeFields } from "./composables/usePostTypeFields.js";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppCheckbox from "@/shared/components/form/toggle/AppCheckbox.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import { Plus, Pencil, Trash2, Save, X, LayoutTemplate } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    postTypes: { type: Array, default: () => [] },
    supportOptions: { type: Array, default: () => [] },
    fieldTypes: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    fieldCreatePathTemplate: { type: String, required: true },
    fieldEditPathTemplate: { type: String, required: true },
    fieldDeletePathTemplate: { type: String, required: true },
    fieldReorderPathTemplate: { type: String, required: true },
    /** The post type this URL is. Decided by the server, not by the browser. */
    activeId: { type: Number, default: null },
});

const {
    items, selected, upsert,
    showCreate, createForm, createErrors, createLoading, openCreate, submitCreate,
    showEdit, editing, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
    toggleSupport,
} = usePostTypesForm(props);

const {
    showField, editingField, fieldForm, fieldErrors, fieldLoading,
    openFieldCreate, openFieldEdit, submitField,
    pendingFieldDelete, fieldDeleteLoading, deleteField,
} = usePostTypeFields(props, selected, upsert);

const fieldTypeOptions = props.fieldTypes.map((type) => ({
    value: type,
    label: t(`backend.post_types.fields.types.${type}`),
}));
</script>

<template>
    <AppNoData v-if="!items.length" :message="t('backend.post_types.empty')">
        <template v-if="can('editorial.post_types.create')" #action>
            <AppButton variant="primary" size="md" v-on:click="openCreate">
                <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.post_types.create") }}
            </AppButton>
        </template>
    </AppNoData>

    <div v-else class="space-y-4">
        <!-- No picker column: the side menu lists the post types, one entry
             per record and one address each. The create button stays, because
             a group header in the menu has nowhere to put one - and it is the
             only way to make the next type. -->
        <div v-if="can('editorial.post_types.create')" class="flex justify-end">
            <AppButton variant="primary" size="md" v-on:click="openCreate">
                <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.post_types.create") }}
            </AppButton>
        </div>

        <!-- Selected type -->
        <section v-if="selected" class="space-y-4">
            <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-primary truncate">{{ selected.label }}</h2>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ selected.slug }}</p>
                    </div>
                    <div class="flex items-center gap-0.5 shrink-0">
                        <AppIconButton
                            v-if="can('editorial.post_types.edit')"
                            color="accent"
                            :title="t('shared.common.edit')"
                            v-on:click="openEdit(selected)"
                        >
                            <Pencil class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            v-if="can('editorial.post_types.delete') && !selected.isBuiltIn"
                            color="rose"
                            :title="t('shared.common.delete')"
                            v-on:click="confirmDelete(selected)"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <AppBadge v-for="support in selected.supports" :key="support" color="gray">
                        {{ t(`backend.post_types.supports_${support}`) }}
                    </AppBadge>
                    <AppBadge v-if="selected.hasArchive" color="accent">
                        {{ t("backend.post_types.has_archive") }}
                    </AppBadge>
                </div>
            </div>

            <!-- Custom fields -->
            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.post_types.fields.title") }}</h3>
                    <AppButton
                        v-if="can('editorial.post_types.edit')"
                        variant="ghost"
                        size="sm"
                        v-on:click="openFieldCreate"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.post_types.fields.create") }}
                    </AppButton>
                </div>

                <AppNoData v-if="!selected.fields.length" :message="t('backend.post_types.fields.empty')" />

                <div class="divide-y divide-line/40">
                    <div
                        v-for="field in selected.fields"
                        :key="field.id"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-primary truncate">{{ field.label }}</p>
                            <p class="text-xs text-muted font-mono mt-0.5 truncate">{{ field.name }}</p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <AppBadge color="gray">{{ t(`backend.post_types.fields.types.${field.type}`) }}</AppBadge>
                            <AppBadge v-if="field.required" color="amber">{{ t("backend.post_types.fields.required") }}</AppBadge>
                            <AppBadge v-if="field.translatable" color="sky">{{ t("backend.post_types.fields.translatable") }}</AppBadge>
                            <AppIconButton
                                v-if="can('editorial.post_types.edit')"
                                color="accent"
                                :title="t('shared.common.edit')"
                                v-on:click="openFieldEdit(field)"
                            >
                                <Pencil class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton
                                v-if="can('editorial.post_types.edit')"
                                color="rose"
                                :title="t('shared.common.delete')"
                                v-on:click="pendingFieldDelete = field"
                            >
                                <Trash2 class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Create / edit a type -->
    </div>

    <!-- Outside the `v-else` on purpose. These modals used to live inside it,
         so with nothing created yet the branch was not rendered and neither
         were they: the empty state's button set the flag and nothing existed
         to react. The first item could never be created, and only the first -
         once one existed the branch rendered and the button worked. -->
    <AppModal
        :show="showCreate"
        :title="t('backend.post_types.create')"
        :icon="LayoutTemplate"
        :closeable="false"
        v-on:close="showCreate = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submitCreate">
            <AppInput
                v-model="createForm.label"
                :label="t('backend.post_types.label')"
                :placeholder="t('backend.post_types.label_placeholder')"
                :error="createErrors.label"
                required
            />
            <!-- What this type is for, in the author's words. The side menu
                 shows it under the name; left blank it says how many posts the
                 type holds instead. -->
            <AppTextarea
                v-model="createForm.description"
                :label="t('backend.post_types.description')"
                :placeholder="t('backend.post_types.description_placeholder')"
                :rows="2"
            />
            <AppInput
                v-model="createForm.slug"
                :label="t('backend.post_types.slug')"
                :placeholder="t('shared.placeholders.slug')"
                :error="createErrors.slug"
                required
            />
            <AppInput
                v-model="createForm.icon"
                :label="t('backend.post_types.icon')"
                :placeholder="t('backend.post_types.icon_placeholder')"
            />
            <AppCheckbox v-model="createForm.hasArchive" :label="t('backend.post_types.has_archive')" :hint="t('backend.post_types.has_archive_hint')" />
            <div class="space-y-2">
                <label class="block text-xs text-secondary uppercase tracking-wide">{{ t("backend.post_types.supports") }}</label>
                <AppCheckbox
                    v-for="support in supportOptions"
                    :key="support"
                    :model-value="createForm.supports.includes(support)"
                    :label="t(`backend.post_types.supports_${support}`)"
                    v-on:update:model-value="toggleSupport(createForm, support)"
                />
            </div>
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
        :title="t('backend.post_types.edit')"
        :icon="Pencil"
        :closeable="false"
        v-on:close="showEdit = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submitEdit">
            <AppInput
                v-model="editForm.label"
                :label="t('backend.post_types.label')"
                :placeholder="t('backend.post_types.label_placeholder')"
                :error="editErrors.label"
                required
            />
            <!-- What this type is for, in the author's words. The side menu
                 shows it under the name; left blank it says how many posts the
                 type holds instead. -->
            <AppTextarea
                v-model="editForm.description"
                :label="t('backend.post_types.description')"
                :placeholder="t('backend.post_types.description_placeholder')"
                :rows="2"
            />
            <AppInput
                v-model="editForm.slug"
                :label="t('backend.post_types.slug')"
                :placeholder="t('shared.placeholders.slug')"
                :error="editErrors.slug"
                :disabled="editing?.isBuiltIn"
                :hint="editing?.isBuiltIn ? t('backend.post_types.slug_locked') : null"
            />
            <AppInput
                v-model="editForm.icon"
                :label="t('backend.post_types.icon')"
                :placeholder="t('backend.post_types.icon_placeholder')"
            />
            <AppCheckbox v-model="editForm.hasArchive" :label="t('backend.post_types.has_archive')" :hint="t('backend.post_types.has_archive_hint')" />
            <div class="space-y-2">
                <label class="block text-xs text-secondary uppercase tracking-wide">{{ t("backend.post_types.supports") }}</label>
                <AppCheckbox
                    v-for="support in supportOptions"
                    :key="support"
                    :model-value="editForm.supports.includes(support)"
                    :label="t(`backend.post_types.supports_${support}`)"
                    v-on:update:model-value="toggleSupport(editForm, support)"
                />
            </div>
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="showEdit = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="editLoading" v-on:click="submitEdit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>

    <!-- Create / edit a field -->
    <AppModal
        :show="showField"
        :title="editingField ? t('backend.post_types.fields.edit') : t('backend.post_types.fields.create')"
        :icon="editingField ? Pencil : Plus"
        :closeable="false"
        v-on:close="showField = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submitField">
            <AppInput
                v-model="fieldForm.label"
                :label="t('backend.post_types.fields.label')"
                :placeholder="t('backend.post_types.fields.label_placeholder')"
                :error="fieldErrors.label"
                required
            />
            <AppInput
                v-model="fieldForm.name"
                :label="t('backend.post_types.fields.name')"
                :placeholder="t('backend.post_types.fields.name_placeholder')"
                :error="fieldErrors.name"
                required
            />
            <AppSelect v-model="fieldForm.type" :label="t('backend.post_types.fields.type')" :options="fieldTypeOptions" />

            <AppTextarea
                v-if="fieldForm.type === 'select'"
                v-model="fieldForm.choices"
                :label="t('backend.post_types.fields.choices')"
                :placeholder="t('shared.placeholders.one_per_line')"
                :hint="t('backend.post_types.fields.choices_hint')"
                :rows="4"
            />

            <AppCheckbox v-model="fieldForm.required" :label="t('backend.post_types.fields.required')" />
            <AppCheckbox v-model="fieldForm.translatable" :label="t('backend.post_types.fields.translatable')" />
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="showField = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="fieldLoading" v-on:click="submitField"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>

    <!-- Deletions -->
    <AppModal
        :show="!!pendingDelete"
        max-width="sm"
        :closeable="false"
        :title="t('shared.common.delete')"
        :icon="Trash2"
        v-on:close="pendingDelete = null"
    >
        <p class="text-sm text-primary">{{ t("backend.post_types.delete_confirm", { label: pendingDelete?.label ?? "" }) }}</p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>

    <AppModal
        :show="!!pendingFieldDelete"
        max-width="sm"
        :closeable="false"
        :title="t('shared.common.delete')"
        :icon="Trash2"
        v-on:close="pendingFieldDelete = null"
    >
        <p class="text-sm text-primary">{{ t("backend.post_types.fields.delete_confirm", { label: pendingFieldDelete?.label ?? "" }) }}</p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="pendingFieldDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="danger" size="md" :loading="fieldDeleteLoading" v-on:click="deleteField"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
