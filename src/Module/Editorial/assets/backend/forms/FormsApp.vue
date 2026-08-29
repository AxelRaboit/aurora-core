<script setup>
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useEditDeleteActions } from "@/shared/composables/useEditDeleteActions.js";
import { useFormsList } from "./composables/useFormsList.js";
import { useFormFields } from "./composables/useFormFields.js";
import { useFormSubmissions } from "./composables/useFormSubmissions.js";
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
import {
    ChevronDown, ChevronLeft, ChevronRight, ChevronUp, ClipboardList,
    Download, Pencil, Plus, Save, Trash2, X,
} from "lucide-vue-next";

const { t, d } = useI18n();
const { can } = usePrivileges();

const props = defineProps({
    forms: { type: Array, default: () => [] },
    locales: { type: Array, default: () => [] },
    fieldTypes: { type: Array, default: () => [] },
    conditionLogics: { type: Array, default: () => [] },
    createPath: { type: String, required: true },
    updatePathTemplate: { type: String, required: true },
    deletePathTemplate: { type: String, required: true },
    fieldCreatePathTemplate: { type: String, required: true },
    fieldEditPathTemplate: { type: String, required: true },
    fieldDeletePathTemplate: { type: String, required: true },
    fieldReorderPathTemplate: { type: String, required: true },
    submissionsPathTemplate: { type: String, required: true },
    exportPathTemplate: { type: String, required: true },
});

const {
    items, selectedId, selected, upsert,
    showEditor, editing, editorForm, errors, loading,
    openCreate, openEdit, submit, addStep, removeStep,
    pendingDelete, deleteLoading, doDelete,
} = useFormsList(props);

const {
    fields, showField, editingField, fieldForm, fieldErrors, fieldLoading,
    typeMeta, typeOptions, logicOptions, conditionSources,
    openFieldCreate, openFieldEdit, submitField,
    addCondition, removeCondition,
    pendingFieldDelete, fieldDeleteLoading, deleteField, move,
} = useFormFields(props, selected, upsert);

// The two actions a field row offers. The reorder arrows beside them stay as they
// are: they are pressed repeatedly, and a sheet would turn each nudge into
// open-click-close.
const fieldActions = useEditDeleteActions({
    can,
    editPermission: "editorial.forms.edit",
    deletePermission: "editorial.forms.edit",
    openEdit: openFieldEdit,
    confirmDelete: (field) => {
        pendingFieldDelete.value = field;
    },
    editDescription: "backend.forms.fields.row_actions.edit_description",
    deleteDescription: "backend.forms.fields.row_actions.delete_description",
});

const {
    submissions, total, page, totalPages, load, goToPage, exportUrl,
} = useFormSubmissions(props, selected);

onMounted(load);

const primaryLocale = computed(() => props.locales[0] ?? "en");

function titleOf(form) {
    return form.translations?.[primaryLocale.value]?.title || `#${form.id}`;
}

function labelOf(field) {
    return field.translations?.[primaryLocale.value]?.label || `#${field.id}`;
}

function formatDate(value) {
    return d(new Date(value), "short");
}
</script>

<template>
    <AppNoData v-if="!items.length" :message="t('backend.forms.empty')">
        <template v-if="can('editorial.forms.create')" #action>
            <AppButton variant="primary" size="md" v-on:click="openCreate">
                <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.forms.create") }}
            </AppButton>
        </template>
    </AppNoData>

    <div v-else class="grid grid-cols-1 lg:grid-cols-[18rem_1fr] gap-4">
        <aside class="space-y-2">
            <AppButton
                v-if="can('editorial.forms.create')"
                variant="primary"
                size="md"
                class="w-full"
                v-on:click="openCreate"
            >
                <Plus class="w-4 h-4" :stroke-width="2" /> {{ t("backend.forms.create") }}
            </AppButton>

            <button
                v-for="form in items"
                :key="form.id"
                type="button"
                class="w-full text-left px-4 py-3 rounded-xl border transition-colors"
                :class="form.id === selectedId
                    ? 'bg-surface border-accent-500/60 text-primary'
                    : 'bg-surface border-line/60 text-secondary hover:text-primary hover:bg-surface-2/40'"
                v-on:click="selectedId = form.id"
            >
                <span class="flex items-center justify-between gap-2">
                    <span class="font-medium truncate">{{ titleOf(form) }}</span>
                    <AppBadge v-if="!form.active" color="amber">{{ t("backend.forms.active") }}</AppBadge>
                </span>
                <span class="block text-xs text-muted mt-0.5 truncate">
                    {{ form.reference }} · {{ t("backend.forms.submissions.title") }} {{ form.submissionCount }}
                </span>
            </button>
        </aside>

        <section v-if="selected" class="space-y-4">
            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold text-primary truncate">{{ titleOf(selected) }}</h2>
                        <p class="text-xs text-muted font-mono mt-0.5 truncate">
                            /{{ primaryLocale }}/forms/{{ selected.translations?.[primaryLocale]?.slug }}
                        </p>
                    </div>
                    <div class="flex items-center gap-0.5 shrink-0">
                        <AppIconButton
                            v-if="can('editorial.forms.edit')"
                            color="accent"
                            :title="t('shared.common.edit')"
                            v-on:click="openEdit(selected)"
                        >
                            <Pencil class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                        <AppIconButton
                            v-if="can('editorial.forms.delete')"
                            color="rose"
                            :title="t('shared.common.delete')"
                            v-on:click="pendingDelete = selected"
                        >
                            <Trash2 class="w-4 h-4" :stroke-width="2" />
                        </AppIconButton>
                    </div>
                </div>
            </div>

            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-primary">{{ t("backend.forms.fields.title") }}</h3>
                    <AppButton
                        v-if="can('editorial.forms.edit')"
                        variant="ghost"
                        size="sm"
                        v-on:click="openFieldCreate"
                    >
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.forms.fields.create") }}
                    </AppButton>
                </div>

                <AppNoData v-if="!fields.length" :message="t('backend.forms.fields.empty')" />

                <div class="divide-y divide-line/40">
                    <div
                        v-for="field in fields"
                        :key="field.id"
                        class="flex items-center justify-between gap-3 py-2.5 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="font-medium text-primary truncate">
                                {{ labelOf(field) }}<span v-if="field.required" class="text-rose-500"> *</span>
                            </p>
                            <p class="text-xs text-muted mt-0.5 truncate">
                                {{ t(`backend.forms.field_types.${field.type}`) }}
                                <span v-if="field.step"> · {{ t("backend.forms.fields.step") }} {{ field.step }}</span>
                                <span v-if="field.conditions?.length"> · {{ t("backend.forms.fields.conditions") }}</span>
                            </p>
                        </div>
                        <div v-if="can('editorial.forms.edit')" class="flex items-center gap-0.5 shrink-0">
                            <AppIconButton :title="t('backend.forms.fields.move_up')" v-on:click="move(field, -1)">
                                <ChevronUp class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton :title="t('backend.forms.fields.move_down')" v-on:click="move(field, 1)">
                                <ChevronDown class="w-4 h-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppRowActions :actions="fieldActions(field)" :label="field.label ?? field.name ?? ''" />
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-surface border border-line rounded-xl p-5 space-y-3">
                <div class="flex items-center justify-between gap-3">
                    <h3 class="text-sm font-semibold text-primary">
                        {{ t("backend.forms.submissions.title") }}
                        <span v-if="total" class="text-muted font-normal">({{ total }})</span>
                    </h3>
                    <AppButton
                        v-if="total"
                        variant="ghost"
                        size="sm"
                        :href="exportUrl()"
                        as="a"
                    >
                        <Download class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.forms.submissions.export") }}
                    </AppButton>
                </div>

                <AppNoData v-if="!submissions.length" :message="t('backend.forms.submissions.empty')" />

                <div v-else class="divide-y divide-line/40">
                    <article v-for="submission in submissions" :key="submission.id" class="py-3 space-y-1">
                        <p class="text-xs text-muted">
                            {{ submission.reference }} · {{ formatDate(submission.submittedAt) }} · {{ submission.locale }}
                        </p>
                        <dl class="text-sm">
                            <div v-for="pair in submission.pairs" :key="pair.label" class="flex gap-2">
                                <dt class="text-secondary shrink-0">{{ pair.label }} :</dt>
                                <dd class="text-primary min-w-0 break-words">{{ pair.value }}</dd>
                            </div>
                        </dl>
                    </article>
                </div>

                <div v-if="totalPages > 1" class="flex items-center justify-center gap-2 pt-1">
                    <AppButton variant="ghost" size="sm" :disabled="page <= 1" v-on:click="goToPage(page - 1)">
                        <ChevronLeft class="w-4 h-4" :stroke-width="2" />
                    </AppButton>
                    <span class="text-xs text-secondary tabular-nums">{{ page }} / {{ totalPages }}</span>
                    <AppButton variant="ghost" size="sm" :disabled="page >= totalPages" v-on:click="goToPage(page + 1)">
                        <ChevronRight class="w-4 h-4" :stroke-width="2" />
                    </AppButton>
                </div>
            </div>
        </section>
    </div>

    <!-- Outside the `v-else` on purpose. These modals used to live inside it,
         so with no form yet the branch was not rendered and neither were they:
         the empty state's create button set `showEditor` and nothing existed
         to react. The first form could never be created, and only the first -
         once one existed the branch rendered and the button worked. -->
    <AppModal
        :show="showEditor"
        :title="editing ? t('backend.forms.edit') : t('backend.forms.create')"
        :icon="ClipboardList"
        :closeable="false"
        v-on:close="showEditor = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submit">
            <AppCheckbox v-model="editorForm.active" :label="t('backend.forms.active')" :hint="t('backend.forms.active_hint')" />
            <AppInput
                v-model="editorForm.notifyEmail"
                :label="t('backend.forms.notify_email')"
                :placeholder="t('shared.placeholders.email')"
                :hint="t('backend.forms.notify_email_hint')"
                :error="errors.notifyEmail"
            />
            <AppInput
                v-model="editorForm.webhookUrl"
                :label="t('backend.forms.webhook_url')"
                :placeholder="t('shared.placeholders.url')"
                :hint="t('backend.forms.webhook_url_hint')"
                :error="errors.webhookUrl"
            />
            <AppCheckbox v-model="editorForm.crmSync" :label="t('backend.forms.crm_sync')" />

            <div v-for="locale in locales" :key="locale" class="space-y-2 border-t border-line/40 pt-3">
                <p class="text-xs uppercase tracking-wide text-muted">{{ locale }}</p>
                <AppInput
                    v-model="editorForm.translations[locale].title"
                    :label="t('backend.forms.title_label')"
                    :placeholder="t('shared.placeholders.title')"
                />
                <AppInput
                    v-model="editorForm.translations[locale].slug"
                    :label="t('backend.forms.slug')"
                    :placeholder="t('shared.placeholders.slug')"
                    :hint="t('backend.forms.slug_hint')"
                    :error="errors[`translations[${locale}].slug`]"
                />
                <AppTextarea
                    v-model="editorForm.translations[locale].description"
                    :label="t('backend.forms.description')"
                    :placeholder="t('shared.placeholders.description')"
                    :rows="2"
                />
            </div>

            <div class="space-y-2 border-t border-line/40 pt-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs text-secondary uppercase tracking-wide">{{ t("backend.forms.steps.title") }}</label>
                    <AppButton variant="ghost" size="sm" v-on:click="addStep">
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.forms.steps.add") }}
                    </AppButton>
                </div>
                <p class="text-xs text-muted">{{ t("backend.forms.steps.hint") }}</p>
                <div v-for="(step, index) in editorForm.steps ?? []" :key="index" class="flex items-center gap-2">
                    <AppInput v-model="step.title" class="flex-1" :placeholder="t('backend.forms.steps.name')" />
                    <AppIconButton color="rose" :title="t('backend.forms.steps.remove')" v-on:click="removeStep(index)">
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </div>
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="showEditor = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="loading" v-on:click="submit"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>

    <AppModal
        :show="showField"
        :title="editingField ? t('backend.forms.fields.edit') : t('backend.forms.fields.create')"
        :icon="editingField ? Pencil : Plus"
        :closeable="false"
        v-on:close="showField = false"
    >
        <form class="space-y-4" v-on:submit.prevent="submitField">
            <AppSelect v-model="fieldForm.type" :label="t('backend.forms.fields.type')" :options="typeOptions" :error="fieldErrors.type" />
            <AppCheckbox v-model="fieldForm.required" :label="t('backend.forms.fields.required')" />
            <AppInput
                v-model.number="fieldForm.step"
                type="number"
                :label="t('backend.forms.fields.step')"
                :placeholder="t('backend.forms.fields.step_placeholder')"
            />

            <div v-for="locale in locales" :key="locale" class="space-y-2 border-t border-line/40 pt-3">
                <p class="text-xs uppercase tracking-wide text-muted">{{ locale }}</p>
                <AppInput
                    v-model="fieldForm.translations[locale].label"
                    :label="t('backend.forms.fields.label')"
                    :placeholder="t('backend.forms.fields.label_placeholder')"
                />
                <AppInput v-model="fieldForm.translations[locale].placeholder" :label="t('backend.forms.fields.placeholder')" />
                <AppTextarea
                    v-if="typeMeta?.hasOptions"
                    v-model="fieldForm.translations[locale].options"
                    :label="t('backend.forms.fields.options')"
                    :placeholder="t('shared.placeholders.one_per_line')"
                    :hint="t('backend.forms.fields.options_hint')"
                    :rows="4"
                    :error="fieldErrors[`translations[${locale}].options`]"
                />
            </div>

            <div class="space-y-2 border-t border-line/40 pt-3">
                <div class="flex items-center justify-between">
                    <label class="block text-xs text-secondary uppercase tracking-wide">{{ t("backend.forms.fields.conditions") }}</label>
                    <AppButton variant="ghost" size="sm" :disabled="!conditionSources.length" v-on:click="addCondition">
                        <Plus class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("backend.forms.fields.add_condition") }}
                    </AppButton>
                </div>
                <p class="text-xs text-muted">{{ t("backend.forms.fields.conditions_hint") }}</p>

                <AppSelect
                    v-if="fieldForm.conditions.length > 1"
                    v-model="fieldForm.conditionsLogic"
                    :options="logicOptions"
                />

                <div v-for="(condition, index) in fieldForm.conditions" :key="index" class="flex items-center gap-2">
                    <AppSelect v-model="condition.fieldId" class="flex-1" :options="conditionSources" :placeholder="t('backend.forms.fields.condition_field')" />
                    <AppInput v-model="condition.value" class="flex-1" :placeholder="t('backend.forms.fields.condition_value')" />
                    <AppIconButton color="rose" :title="t('shared.common.delete')" v-on:click="removeCondition(index)">
                        <Trash2 class="w-4 h-4" :stroke-width="2" />
                    </AppIconButton>
                </div>
            </div>
        </form>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="showField = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="primary" size="md" :loading="fieldLoading" v-on:click="submitField"><Save class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}</AppButton>
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
        <p class="text-sm text-primary">{{ t("backend.forms.delete_confirm", { title: pendingDelete ? titleOf(pendingDelete) : "" }) }}</p>
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
        <p class="text-sm text-primary">{{ t("backend.forms.fields.delete_confirm", { label: pendingFieldDelete ? labelOf(pendingFieldDelete) : "" }) }}</p>
        <template #footer>
            <AppModalFooter>
                <AppButton variant="ghost" size="md" v-on:click="pendingFieldDelete = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}</AppButton>
                <AppButton variant="danger" size="md" :loading="fieldDeleteLoading" v-on:click="deleteField"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}</AppButton>
            </AppModalFooter>
        </template>
    </AppModal>
</template>
