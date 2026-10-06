<script setup>
import { computed, inject, ref } from "vue";
import { useI18n } from "vue-i18n";
import { ChevronDown, ChevronUp, Eye, Layers, ListPlus, Pencil, Plus, Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppNoData from "@/shared/components/feedback/AppNoData.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { useNarrowContainer } from "@/shared/composables/list/useNarrowContainer.js";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import { splitOptions } from "../composables/useFormFields.js";
import FieldTypePicker from "./FieldTypePicker.vue";
import FormFieldPanel from "./FormFieldPanel.vue";
import FormPreview from "./FormPreview.vue";
import { iconForType } from "./fieldTypeIcons.js";

/**
 * A form's questions, and the form as it will be seen.
 *
 * On the left the list, grouped by step when there are steps: a step is a
 * title above its questions, no longer a number to type into each question
 * without seeing the others. On the right the preview, and above it the open
 * question - you adjust and see the result in the same place.
 *
 * When space runs short (a phone, or the sidemenu open on a small screen),
 * there is no right side: the question opens full screen, the preview moves
 * below the list.
 */
const props = defineProps({
    form: { type: Object, required: true },
});

const { t } = useI18n();
const { can } = usePrivileges();
const canEdit = can("editorial.forms.edit");

const {
    fields, hasSteps, fieldsOfStep, editorOpen, editingField, draft,
    openFieldCreate, openFieldEdit, closeEditor, move, canMove,
    pendingFieldDelete, fieldDeleteLoading, deleteField,
} = inject("formFields");
const { fieldTypes, editLocale, labelOf, saveSteps, savingSteps } = inject("formEditor");

/**
 * Two columns when there is room, measured on the container and never on the
 * window: the sidemenu takes 480 pixels from 1024 on, and a Tailwind "lg:"
 * opened both columns where 410 were left for the two of them. 900 is the
 * list at ease next to a 28rem panel.
 */
const { container, isNarrow } = useNarrowContainer(900);
const wide = computed(() => !isNarrow.value);

// ── Add a question ──────────────────────────────────────────────────────────

const picking = ref(false);
const pickStep = ref(null);

function startCreate(step = null) {
    pickStep.value = step;
    picking.value = true;
}

function pick(type) {
    picking.value = false;
    openFieldCreate(type, pickStep.value);
}

// ── Steps ───────────────────────────────────────────────────────────────────

const steps = computed(() => props.form.steps ?? []);

/** The titles as they are typed, saved when leaving the field. */
const stepTitles = ref(steps.value.map((step) => step.title ?? ""));

function syncTitles() {
    stepTitles.value = steps.value.map((step) => step.title ?? "");
}

async function commitSteps(next) {
    await saveSteps(next);
    syncTitles();
}

function renameStep(index) {
    const title = (stepTitles.value[index] ?? "").trim();
    if (title === (steps.value[index]?.title ?? "")) return;

    void commitSteps(steps.value.map((step, i) => ({ title: i === index ? title : step.title ?? "" })));
}

/**
 * Two steps at once: a single one is nothing like a multi-step form, and the
 * second is the one people come for. The questions already there stay in the
 * first.
 */
function splitIntoSteps() {
    void commitSteps([{ title: "" }, { title: "" }]);
}

function addStep() {
    void commitSteps([...steps.value.map((step) => ({ title: step.title ?? "" })), { title: "" }]);
}

/**
 * Only the last step can be removed, and only when empty. Removing one in the
 * middle renumbers the following ones, and their questions would change step
 * without anyone asking; removing a full one would make them vanish from the
 * form. Removing the last remaining one brings everything back onto one page.
 */
function canRemoveStep(index) {
    return index === steps.value.length - 1 && (1 === steps.value.length || !fieldsOfStep(index + 1).length);
}

function removeStep(index) {
    const next = steps.value.filter((_, i) => i !== index).map((step) => ({ title: step.title ?? "" }));
    void commitSteps(next.length ? next : null);
}

/** The groups the list draws: one per step, or a single one without a title. */
const groups = computed(() =>
    hasSteps.value
        ? steps.value.map((step, index) => ({ number: index + 1, fields: fieldsOfStep(index + 1) }))
        : [{ number: null, fields: fields.value }],
);

// ── One row ─────────────────────────────────────────────────────────────────

function rowActions(field) {
    return [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("shared.common.edit"),
            description: t("suite.forms.fields.row_actions.edit_description"),
            onSelect: () => openFieldEdit(field),
        },
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("suite.forms.fields.row_actions.delete_description"),
            onSelect: () => (pendingFieldDelete.value = field),
        },
    ];
}

const typeLabel = (type) => t(`suite.forms.field_types.${type}`);

// ── Preview ─────────────────────────────────────────────────────────────────

function readerField(field, locale) {
    const translation = field.translations?.[locale] ?? {};

    return {
        id: field.id,
        type: field.type,
        required: field.required,
        label: translation.label ?? "",
        placeholder: translation.placeholder || null,
        options: translation.options ?? [],
        conditions: field.conditions ?? [],
        conditionsLogic: field.conditionsLogic ?? "and",
        step: field.step ?? null,
    };
}

/**
 * The form in the site's format, including the open question as it is being
 * typed. A new question takes an id that cannot belong to any other, and is
 * added at the end of its step.
 */
const previewForm = computed(() => {
    const locale = editLocale.value;
    const live = editorOpen.value
        ? {
            ...draft.value,
            id: editingField.value?.id ?? -1,
            conditions: draft.value.conditions.filter((condition) => condition.fieldId),
            step: hasSteps.value ? (draft.value.step ?? 1) : null,
            translations: Object.fromEntries(
                Object.entries(draft.value.translations).map(([code, translation]) => [
                    code,
                    { ...translation, options: splitOptions(translation.options) },
                ]),
            ),
        }
        : null;

    const ordered = fields.value.map((field) => (live && field.id === live.id ? live : field));
    if (live && !editingField.value) ordered.push(live);

    return {
        id: props.form.id,
        title: props.form.translations?.[locale]?.title ?? "",
        description: props.form.translations?.[locale]?.description ?? null,
        steps: hasSteps.value ? props.form.steps : null,
        fields: ordered.map((field) => readerField(field, locale)),
    };
});
</script>

<template>
    <div
        ref="container"
        class="grid gap-4"
        :class="wide ? 'grid-cols-[minmax(0,1fr)_minmax(0,28rem)] items-start' : 'grid-cols-1'"
    >
        <div class="aurora-card min-w-0 space-y-4 p-3 sm:p-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="min-w-0">
                    <h3 class="text-sm font-semibold text-primary">
                        {{ t("suite.forms.fields.title") }}
                        <span class="font-normal text-muted">({{ fields.length }})</span>
                    </h3>
                    <p class="m-0 mt-0.5 text-xs text-muted">{{ t("suite.forms.fields.intro") }}</p>
                </div>
                <AppButton
                    v-if="canEdit"
                    variant="primary"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="startCreate()"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.forms.fields.create") }}
                </AppButton>
            </div>

            <!-- One page or steps, said up front: it is the shape of the
                 form, not one setting among others. -->
            <div v-if="!hasSteps" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-surface-2/40 px-3 py-2">
                <span class="flex items-center gap-2 text-xs text-secondary">
                    <Layers class="h-3.5 w-3.5 text-muted" :stroke-width="2" /> {{ t("suite.forms.steps.single_page") }}
                </span>
                <AppButton
                    v-if="canEdit"
                    variant="ghost"
                    size="sm"
                    :loading="savingSteps"
                    v-on:click="splitIntoSteps"
                >
                    {{ t("suite.forms.steps.split") }}
                </AppButton>
            </div>

            <AppNoData v-if="!fields.length && !hasSteps" :message="t('suite.forms.fields.empty')" :hint="t('suite.forms.fields.empty_hint')" />

            <section v-for="group in groups" :key="group.number ?? 'all'" class="space-y-1">
                <!-- A step's title is typed in place, above its questions,
                     and saved when leaving the field. -->
                <div v-if="group.number" class="flex items-center gap-2 border-b border-line/40 pb-2">
                    <span class="shrink-0 rounded-md bg-accent-600/15 px-2 py-0.5 text-xs font-medium text-accent-400">
                        {{ t("suite.forms.steps.numbered", { number: group.number }) }}
                    </span>
                    <AppInput
                        v-model="stepTitles[group.number - 1]"
                        class="min-w-0 flex-1"
                        variant="ghost"
                        :readonly="!canEdit"
                        :placeholder="t('suite.forms.steps.name')"
                        v-on:focusout="renameStep(group.number - 1)"
                        v-on:keydown.enter.prevent="renameStep(group.number - 1)"
                    />
                    <AppIconButton
                        v-if="canEdit"
                        color="rose"
                        :disabled="!canRemoveStep(group.number - 1)"
                        :title="canRemoveStep(group.number - 1) ? t('suite.forms.steps.remove') : t('suite.forms.steps.remove_blocked')"
                        v-on:click="removeStep(group.number - 1)"
                    >
                        <X class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <p v-if="group.number && !group.fields.length" class="m-0 py-2 text-xs text-muted">
                    {{ t("suite.forms.steps.empty") }}
                </p>

                <div class="divide-y divide-line/40">
                    <div
                        v-for="field in group.fields"
                        :key="field.id"
                        class="flex items-center gap-2 rounded-md py-2 transition-colors"
                        :class="editingField?.id === field.id ? 'bg-accent-600/10 px-2' : ''"
                    >
                        <!-- The whole line opens the question: it is the
                             gesture people come for, the menu stays for
                             deleting. -->
                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-center gap-3 text-left"
                            :disabled="!canEdit"
                            v-on:click="openFieldEdit(field)"
                        >
                            <component :is="iconForType(field.type)" class="h-4 w-4 shrink-0 text-muted" :stroke-width="2" />
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-primary">
                                    {{ labelOf(field) }}<span v-if="field.required" class="text-rose-500"> *</span>
                                </span>
                                <span class="block truncate text-xs text-muted">
                                    {{ typeLabel(field.type) }}
                                    <template v-if="field.conditions?.length">
                                        · <Eye class="inline h-3 w-3 align-[-2px]" :stroke-width="2" /> {{ t("suite.forms.fields.conditional_badge") }}
                                    </template>
                                </span>
                            </span>
                        </button>
                        <div v-if="canEdit" class="flex shrink-0 items-center gap-0.5">
                            <AppIconButton :disabled="!canMove(field, -1)" :title="t('suite.forms.fields.move_up')" v-on:click="move(field, -1)">
                                <ChevronUp class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton :disabled="!canMove(field, 1)" :title="t('suite.forms.fields.move_down')" v-on:click="move(field, 1)">
                                <ChevronDown class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppRowActions :actions="rowActions(field)" :label="labelOf(field)" />
                        </div>
                    </div>
                </div>

                <AppButton
                    v-if="group.number && canEdit"
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="startCreate(group.number)"
                >
                    <ListPlus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.forms.steps.add_question_here") }}
                </AppButton>
            </section>

            <div v-if="hasSteps && canEdit" class="border-t border-line/40 pt-3">
                <AppButton
                    variant="ghost"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    :loading="savingSteps"
                    v-on:click="addStep"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.forms.steps.add") }}
                </AppButton>
            </div>
        </div>

        <!-- The right column: the open question, then the preview below,
             which already shows it as it is being typed. -->
        <div class="min-w-0 space-y-4">
            <div v-if="editorOpen && wide" class="aurora-card border-accent-500/40 p-3 sm:p-5">
                <FormFieldPanel />
            </div>
            <FormPreview :form="previewForm" />
        </div>

        <!-- On a phone, the question full screen: a side panel has no side
             to sit on there. -->
        <AppModal
            :show="editorOpen && !wide"
            mobile-fullscreen
            :closeable="false"
            :title="t(editingField ? 'suite.forms.fields.edit' : 'suite.forms.fields.create')"
            :icon="iconForType(draft.type)"
            v-on:close="closeEditor"
        >
            <FormFieldPanel bare />
        </AppModal>

        <FieldTypePicker :show="picking" :field-types="fieldTypes" v-on:close="picking = false" v-on:pick="pick" />

        <AppModal
            :show="!!pendingFieldDelete"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="pendingFieldDelete = null"
        >
            <p class="text-sm text-primary">
                {{ t("suite.forms.fields.delete_confirm", { label: pendingFieldDelete ? labelOf(pendingFieldDelete) : "" }) }}
            </p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="pendingFieldDelete = null">
                        <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                    </AppButton>
                    <AppButton variant="danger" size="md" :loading="fieldDeleteLoading" v-on:click="deleteField">
                        <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
                    </AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
