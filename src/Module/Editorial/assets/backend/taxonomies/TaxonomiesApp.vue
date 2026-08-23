<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useTaxonomiesForm } from "./composables/useTaxonomiesForm.js";
import { useTaxonomyTerms } from "./composables/useTaxonomyTerms.js";
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
import { Plus, Pencil, Trash2, Save, X, Tags, ChevronUp, ChevronDown } from "lucide-vue-next";

const { t } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    taxonomies: { type: Array, default: () => [] },
    postTypes: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    termCreatePathTemplate: { type: String, required: true },
    termEditPathTemplate: { type: String, required: true },
    termDeletePathTemplate: { type: String, required: true },
    termReorderPathTemplate: { type: String, required: true },
});

const {
    items, selectedId, selected, upsert,
    showCreate, createForm, createErrors, createLoading, openCreate, submitCreate,
    showEdit, editing, editForm, editErrors, editLoading, openEdit, submitEdit,
    pendingDelete, deleteLoading, confirmDelete, doDelete,
    togglePostType,
} = useTaxonomiesForm(props);

const {
    rows, showTerm, editingTerm, form, termErrors, termLoading, parentOptions,
    openTermCreate, openTermEdit, submitTerm,
    pendingTermDelete, termDeleteLoading, deleteTerm, move,
} = useTaxonomyTerms(props, selected, upsert);

// The two actions a term row offers. The reorder arrows beside them stay as they
// are: they are pressed repeatedly, and a sheet would turn each nudge into
// open-click-close.
const termActions = useEditDeleteActions({
    can,
    editPermission: "editorial.taxonomies.edit",
    deletePermission: "editorial.taxonomies.edit",
    openEdit: openTermEdit,
    confirmDelete: (term) => {
        pendingTermDelete.value = term;
    },
    editDescription: "backend.taxonomies.terms.row_actions.edit_description",
    deleteDescription: "backend.taxonomies.terms.row_actions.delete_description",
});

const primaryLocale = computed(() => props.locales[0] ?? "en");

function labelOf(taxonomy) {
    return taxonomy.translations?.[primaryLocale.value]?.label || taxonomy.slug;
}

function nameOf(term) {
    return term.translations?.[primaryLocale.value]?.name || `#${term.id}`;
}
</script>

<template>
    <AppNoData v-if="!items.length" :message="t('backend.taxonomies.empty')">
        <template v-if="can('editorial.taxonomies.create')" #action>
            <AppButton variant="primary" size="md" v-on:click="openCreate">
                <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.taxonomies.create") }}
            </AppButton>
        </template>
    </AppNoData>

    <div v-else class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-4">
        <aside class="space-y-2">
            <AppButton
                v-if="can('editorial.taxonomies.create')"
                variant="primary"
                size="md"
                class="w-full"
                v-on:click="openCreate"
            >
                <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.taxonomies.create") }}
            </AppButton>

            <button
                v-for="taxonomy in items"
                :key="taxonomy.id"
                type="button"
                class="w-full text-left px-4 py-3 rounded-xl border transition-colors"
                :class="taxonomy.id === selectedId
                    ? 'bg-surface border-accent-500/60 text-primary'
                    : 'bg-surface border-line/60 text-secondary hover:text-primary hover:bg-surface-2/40'"
                v-on:click="selectedId = taxonomy.id"
            >
                <span class="flex items-center justify-between gap-2">
                    <span class="font-medium truncate">{{ labelOf(taxonomy) }}</span>
                    <AppBadge v-if="taxonomy.isBuiltIn" color="gray">{{ t("backend.taxonomies.built_in") }}</AppBadge>
                </span>
                <span class="block text-xs text-muted font-mono mt-0.5 truncate">{{ taxonomy.slug }}</span>
            </button>
        </aside>

        <section v-if="selected" class="space-y-4">
            <div class="bg-surface border border-line rounded-xl p-5 space-y-4">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-primary truncate">{{ labelOf(selected) }}</h2>
                        <p class="text-xs text-muted font-mono mt-0.5">{{ selected.slug }}</p>
                    </div>
                    <div class="flex items-center gap-0.5 shrink-0">
                        <AppIconButton
                            v-if="can('editorial.taxonomies.edit')"
                            color="accent"
                            :title="t('shared.common.edit')"
                            v-on:click="openEdit(selected)"
                        >
                            <Pencil class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            v-if="can('editorial.taxonomies.delete') && !selected.isBuiltIn"
                            color="rose"
                            :title="t('shared.common.delete')"
                            v-on:click="confirmDelete(selected)"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <AppBadge v-if="selected.hierarchical" color="accent">{{ t("backend.taxonomies.hierarchical") }}</AppBadge>
                    <AppBadge
                        v-for="postType in postTypes.filter((item) => selected.postTypeIds.includes(item.id))"
                        :key="postType.id"
                        color="gray"
                    >
                        {{ postType.label }}
                    </AppBadge>
                </div>
            </div>

            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.taxonomies.terms.title") }}</h3>
                    <AppButton
                        v-if="can('editorial.taxonomies.edit')"
                        variant="ghost"
                        size="sm"
                        v-on:click="openTermCreate"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.taxonomies.terms.create") }}
                    </AppButton>
                </div>

                <AppNoData v-if="!rows.length" :message="t('backend.taxonomies.terms.empty')" />

                <div class="divide-y divide-line/40">
                    <div
                        v-for="term in rows"
                        :key="term.id"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <div class="min-w-0" :style="{ paddingLeft: `${term.depth * 1.25}rem` }">
                            <p class="font-medium text-primary truncate">{{ nameOf(term) }}</p>
                            <p class="text-xs text-muted font-mono mt-0.5 truncate">
                                {{ term.translations?.[primaryLocale]?.slug }}
                                <span v-if="term.reference"> · {{ term.reference }}</span>
                            </p>
                        </div>
                        <div v-if="can('editorial.taxonomies.edit')" class="flex items-center gap-0.5 shrink-0">
                            <AppIconButton :title="t('backend.taxonomies.terms.move_up')" v-on:click="move(term, -1)">
                                <ChevronUp class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton :title="t('backend.taxonomies.terms.move_down')" v-on:click="move(term, 1)">
                                <ChevronDown class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppRowActions :actions="termActions(term)" :label="term.label ?? term.name ?? ''" />
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <AppModal
            :show="showCreate"
            :title="t('backend.taxonomies.create')"
            :icon="Tags"
            :closeable="false"
            v-on:close="showCreate = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitCreate">
                <AppInput
                    v-model="createForm.slug"
                    :label="t('backend.taxonomies.slug')"
                    :placeholder="t('shared.placeholders.slug')"
                    :error="createErrors.slug"
                    required
                />
                <AppCheckbox v-model="createForm.hierarchical" :label="t('backend.taxonomies.hierarchical')" :hint="t('backend.taxonomies.hierarchical_hint')" />

                <div v-for="locale in locales" :key="locale" class="space-y-2 border-t border-line/40 pt-3">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ locale }}</p>
                    <AppInput
                        v-model="createForm.translations[locale].label"
                        :label="t('backend.taxonomies.label')"
                        :placeholder="t('backend.taxonomies.label_placeholder')"
                    />
                    <AppTextarea
                        v-model="createForm.translations[locale].description"
                        :label="t('backend.taxonomies.description')"
                        :placeholder="t('shared.placeholders.description')"
                        :rows="2"
                    />
                </div>

                <div class="space-y-2 border-t border-line/40 pt-3">
                    <label class="block text-xs text-secondary uppercase tracking-wide">{{ t("backend.taxonomies.post_types") }}</label>
                    <AppCheckbox
                        v-for="postType in postTypes"
                        :key="postType.id"
                        :model-value="createForm.postTypeIds.includes(postType.id)"
                        :label="postType.label"
                        v-on:update:model-value="togglePostType(createForm, postType.id)"
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
            :title="t('backend.taxonomies.edit')"
            :icon="Pencil"
            :closeable="false"
            v-on:close="showEdit = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitEdit">
                <AppInput
                    v-model="editForm.slug"
                    :label="t('backend.taxonomies.slug')"
                    :placeholder="t('shared.placeholders.slug')"
                    :error="editErrors.slug"
                    :disabled="editing?.isBuiltIn"
                    :hint="editing?.isBuiltIn ? t('backend.taxonomies.slug_locked') : null"
                />
                <AppCheckbox
                    v-model="editForm.hierarchical"
                    :label="t('backend.taxonomies.hierarchical')"
                    :hint="t('backend.taxonomies.hierarchical_hint')"
                    :disabled="editing?.isBuiltIn"
                />

                <div v-for="locale in locales" :key="locale" class="space-y-2 border-t border-line/40 pt-3">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ locale }}</p>
                    <AppInput
                        v-model="editForm.translations[locale].label"
                        :label="t('backend.taxonomies.label')"
                        :placeholder="t('backend.taxonomies.label_placeholder')"
                    />
                    <AppTextarea
                        v-model="editForm.translations[locale].description"
                        :label="t('backend.taxonomies.description')"
                        :placeholder="t('shared.placeholders.description')"
                        :rows="2"
                    />
                </div>

                <div class="space-y-2 border-t border-line/40 pt-3">
                    <label class="block text-xs text-secondary uppercase tracking-wide">{{ t("backend.taxonomies.post_types") }}</label>
                    <AppCheckbox
                        v-for="postType in postTypes"
                        :key="postType.id"
                        :model-value="editForm.postTypeIds.includes(postType.id)"
                        :label="postType.label"
                        v-on:update:model-value="togglePostType(editForm, postType.id)"
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

        <AppModal
            :show="showTerm"
            :title="editingTerm ? t('backend.taxonomies.terms.edit') : t('backend.taxonomies.terms.create')"
            :icon="editingTerm ? Pencil : Plus"
            :closeable="false"
            v-on:close="showTerm = false"
        >
            <form class="space-y-4" v-on:submit.prevent="submitTerm">
                <AppSelect
                    v-if="selected?.hierarchical"
                    v-model="form.parentId"
                    :label="t('backend.taxonomies.terms.parent')"
                    :placeholder="t('backend.taxonomies.terms.no_parent')"
                    :options="parentOptions"
                    :error="termErrors.parentId"
                />

                <div v-for="locale in locales" :key="locale" class="space-y-2 border-t border-line/40 pt-3">
                    <p class="text-xs uppercase tracking-wide text-muted">{{ locale }}</p>
                    <AppInput
                        v-model="form.translations[locale].name"
                        :label="t('backend.taxonomies.terms.name')"
                        :placeholder="t('shared.placeholders.name')"
                    />
                    <AppInput
                        v-model="form.translations[locale].slug"
                        :label="t('backend.taxonomies.terms.slug')"
                        :placeholder="t('shared.placeholders.slug')"
                        :hint="t('backend.taxonomies.terms.slug_hint')"
                    />
                    <AppTextarea
                        v-model="form.translations[locale].description"
                        :label="t('backend.taxonomies.terms.description')"
                        :placeholder="t('shared.placeholders.description')"
                        :rows="2"
                    />
                </div>
            </form>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="showTerm = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="primary" size="md" :loading="termLoading" v-on:click="submitTerm"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
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
            <p class="text-sm text-primary">{{ t("backend.taxonomies.delete_confirm", { slug: pendingDelete?.slug ?? "" }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="deleteLoading" v-on:click="doDelete"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="!!pendingTermDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingTermDelete = null"
        >
            <p class="text-sm text-primary">{{ t("backend.taxonomies.terms.delete_confirm", { name: pendingTermDelete ? nameOf(pendingTermDelete) : "" }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingTermDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                    <AppButton variant="danger" size="md" :loading="termDeleteLoading" v-on:click="deleteTerm"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
