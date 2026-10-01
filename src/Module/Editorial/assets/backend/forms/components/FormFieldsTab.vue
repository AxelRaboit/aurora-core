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
 * Les questions d'un formulaire, et le formulaire tel qu'on le verra.
 *
 * À gauche la liste, rangée par étape quand il y en a : une étape est un
 * titre au-dessus de ses questions, et plus un numéro à taper dans chaque
 * question sans voir les autres. À droite l'aperçu, et au-dessus de lui la
 * question ouverte - on règle et on regarde le résultat au même endroit.
 *
 * Quand la place manque (téléphone, ou sidemenu ouverte sur un petit écran),
 * il n'y a pas de droite : la question s'ouvre en plein écran, l'aperçu passe
 * sous la liste.
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
 * Deux colonnes quand la place le permet, mesurée sur le conteneur et jamais
 * sur la fenêtre : la sidemenu prend 480 pixels dès 1024, et un « lg: » de
 * Tailwind ouvrait les deux colonnes là où il en restait 410 pour les deux.
 * 900, c'est la liste à l'aise à côté d'un panneau de 28rem.
 */
const { container, isNarrow } = useNarrowContainer(900);
const wide = computed(() => !isNarrow.value);

// ── Ajouter une question ────────────────────────────────────────────────────

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

// ── Étapes ──────────────────────────────────────────────────────────────────

const steps = computed(() => props.form.steps ?? []);

/** Les titres tels qu'on les tape, enregistrés en quittant le champ. */
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
 * Deux étapes d'un coup : une seule n'a rien d'un formulaire en plusieurs
 * temps, et la seconde est celle qu'on vient chercher. Les questions déjà là
 * restent dans la première.
 */
function splitIntoSteps() {
    void commitSteps([{ title: "" }, { title: "" }]);
}

function addStep() {
    void commitSteps([...steps.value.map((step) => ({ title: step.title ?? "" })), { title: "" }]);
}

/**
 * Seule la dernière étape se retire, et seulement vide. Retirer celle du
 * milieu renumérote les suivantes, et leurs questions changeraient d'étape
 * sans qu'on l'ait demandé ; en retirer une pleine les ferait disparaître du
 * formulaire. La dernière qui reste, elle, ramène tout sur une page.
 */
function canRemoveStep(index) {
    return index === steps.value.length - 1 && (1 === steps.value.length || !fieldsOfStep(index + 1).length);
}

function removeStep(index) {
    const next = steps.value.filter((_, i) => i !== index).map((step) => ({ title: step.title ?? "" }));
    void commitSteps(next.length ? next : null);
}

/** Les groupes que la liste dessine : un par étape, ou un seul sans titre. */
const groups = computed(() =>
    hasSteps.value
        ? steps.value.map((step, index) => ({ number: index + 1, fields: fieldsOfStep(index + 1) }))
        : [{ number: null, fields: fields.value }],
);

// ── Une ligne ───────────────────────────────────────────────────────────────

function rowActions(field) {
    return [
        {
            key: "edit",
            color: "accent",
            icon: Pencil,
            title: t("shared.common.edit"),
            description: t("backend.forms.fields.row_actions.edit_description"),
            onSelect: () => openFieldEdit(field),
        },
        {
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.forms.fields.row_actions.delete_description"),
            onSelect: () => (pendingFieldDelete.value = field),
        },
    ];
}

const typeLabel = (type) => t(`backend.forms.field_types.${type}`);

// ── Aperçu ──────────────────────────────────────────────────────────────────

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
 * Le formulaire au format du site, la question ouverte comprise telle qu'on
 * la tape. Une question nouvelle prend un identifiant qui ne peut appartenir à
 * aucune autre, et s'ajoute à la fin de son étape.
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
                        {{ t("backend.forms.fields.title") }}
                        <span class="font-normal text-muted">({{ fields.length }})</span>
                    </h3>
                    <p class="m-0 mt-0.5 text-xs text-muted">{{ t("backend.forms.fields.intro") }}</p>
                </div>
                <AppButton
                    v-if="canEdit"
                    variant="primary"
                    size="sm"
                    class="w-full justify-center sm:w-auto"
                    v-on:click="startCreate()"
                >
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.forms.fields.create") }}
                </AppButton>
            </div>

            <!-- Une page ou des étapes, dit d'emblée : c'est la forme du
                 formulaire, pas un réglage parmi d'autres. -->
            <div v-if="!hasSteps" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line bg-surface-2/40 px-3 py-2">
                <span class="flex items-center gap-2 text-xs text-secondary">
                    <Layers class="h-3.5 w-3.5 text-muted" :stroke-width="2" /> {{ t("backend.forms.steps.single_page") }}
                </span>
                <AppButton
                    v-if="canEdit"
                    variant="ghost"
                    size="sm"
                    :loading="savingSteps"
                    v-on:click="splitIntoSteps"
                >
                    {{ t("backend.forms.steps.split") }}
                </AppButton>
            </div>

            <AppNoData v-if="!fields.length && !hasSteps" :message="t('backend.forms.fields.empty')" :hint="t('backend.forms.fields.empty_hint')" />

            <section v-for="group in groups" :key="group.number ?? 'all'" class="space-y-1">
                <!-- Le titre d'une étape se tape à sa place, au-dessus de ses
                     questions, et s'enregistre en quittant le champ. -->
                <div v-if="group.number" class="flex items-center gap-2 border-b border-line/40 pb-2">
                    <span class="shrink-0 rounded-md bg-accent-600/15 px-2 py-0.5 text-xs font-medium text-accent-400">
                        {{ t("backend.forms.steps.numbered", { number: group.number }) }}
                    </span>
                    <AppInput
                        v-model="stepTitles[group.number - 1]"
                        class="min-w-0 flex-1"
                        variant="ghost"
                        :readonly="!canEdit"
                        :placeholder="t('backend.forms.steps.name')"
                        v-on:focusout="renameStep(group.number - 1)"
                        v-on:keydown.enter.prevent="renameStep(group.number - 1)"
                    />
                    <AppIconButton
                        v-if="canEdit"
                        color="rose"
                        :disabled="!canRemoveStep(group.number - 1)"
                        :title="canRemoveStep(group.number - 1) ? t('backend.forms.steps.remove') : t('backend.forms.steps.remove_blocked')"
                        v-on:click="removeStep(group.number - 1)"
                    >
                        <X class="h-4 w-4" :stroke-width="2" />
                    </AppIconButton>
                </div>

                <p v-if="group.number && !group.fields.length" class="m-0 py-2 text-xs text-muted">
                    {{ t("backend.forms.steps.empty") }}
                </p>

                <div class="divide-y divide-line/40">
                    <div
                        v-for="field in group.fields"
                        :key="field.id"
                        class="flex items-center gap-2 rounded-md py-2 transition-colors"
                        :class="editingField?.id === field.id ? 'bg-accent-600/10 px-2' : ''"
                    >
                        <!-- Toute la ligne ouvre la question : c'est le geste
                             qu'on vient faire, le menu reste pour supprimer. -->
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
                                        · <Eye class="inline h-3 w-3 align-[-2px]" :stroke-width="2" /> {{ t("backend.forms.fields.conditional_badge") }}
                                    </template>
                                </span>
                            </span>
                        </button>
                        <div v-if="canEdit" class="flex shrink-0 items-center gap-0.5">
                            <AppIconButton :disabled="!canMove(field, -1)" :title="t('backend.forms.fields.move_up')" v-on:click="move(field, -1)">
                                <ChevronUp class="h-4 w-4" :stroke-width="2" />
                            </AppIconButton>
                            <AppIconButton :disabled="!canMove(field, 1)" :title="t('backend.forms.fields.move_down')" v-on:click="move(field, 1)">
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
                    <ListPlus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.forms.steps.add_question_here") }}
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
                    <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("backend.forms.steps.add") }}
                </AppButton>
            </div>
        </div>

        <!-- La colonne de droite : la question ouverte, puis l'aperçu dessous,
             qui la montre déjà telle qu'on la tape. -->
        <div class="min-w-0 space-y-4">
            <div v-if="editorOpen && wide" class="aurora-card border-accent-500/40 p-3 sm:p-5">
                <FormFieldPanel />
            </div>
            <FormPreview :form="previewForm" />
        </div>

        <!-- Sur téléphone, la question en plein écran : un panneau de côté n'y a
             pas de côté où se mettre. -->
        <AppModal
            :show="editorOpen && !wide"
            mobile-fullscreen
            :closeable="false"
            :title="t(editingField ? 'backend.forms.fields.edit' : 'backend.forms.fields.create')"
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
                {{ t("backend.forms.fields.delete_confirm", { label: pendingFieldDelete ? labelOf(pendingFieldDelete) : "" }) }}
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
