<script setup>
import { computed, inject } from "vue";
import { useI18n } from "vue-i18n";
import { Plus, Save, Trash2, X } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppChoiceRow from "@/shared/components/form/select/AppChoiceRow.vue";
import AppIconButton from "@/shared/components/action/AppIconButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppMessage from "@/shared/components/feedback/AppMessage.vue";
import AppSelect from "@/shared/components/form/select/AppSelect.vue";
import AppTab from "@/shared/components/nav/AppTab.vue";
import AppTextarea from "@/shared/components/form/input/AppTextarea.vue";
import AppToggle from "@/shared/components/form/toggle/AppToggle.vue";
import { iconForType } from "./fieldTypeIcons.js";

/**
 * Le réglage d'une question, dans l'ordre où on se le pose.
 *
 * Ce qu'on demande, dans chaque langue ; si la réponse est obligatoire ;
 * où elle se place ; quand elle s'affiche. L'ancienne fenêtre posait tout
 * d'un bloc, les langues empilées les unes sous les autres et les conditions
 * réduites à deux cases sans phrase pour les relier. Ici une langue à la fois,
 * et la condition se lit comme une phrase.
 *
 * Le même composant sert au panneau de droite sur ordinateur et à la fenêtre
 * plein écran sur téléphone : il prend son état par `inject`, et l'écran qui
 * l'accueille ne décide que de la place.
 */
defineProps({
    /** Vrai dans la fenêtre du téléphone, qui porte déjà son titre et sa croix. */
    bare: { type: Boolean, default: false },
});

const { t } = useI18n();

const {
    draft, editingField, typeMeta, conditionSources, fieldErrors, fieldLoading,
    hasSteps, submitField, closeEditor, addCondition, removeCondition, pendingFieldDelete,
} = inject("formFields");
const { locales, fieldTypes, conditionLogics, steps, editLocale, labelOf } = inject("formEditor");

const typeOptions = computed(() =>
    fieldTypes.map((type) => ({ value: type.value, label: t(type.labelKey) })),
);

const stepOptions = computed(() =>
    (steps.value ?? []).map((step, index) => ({
        value: index + 1,
        label: step.title
            ? t("suite.forms.steps.numbered_titled", { number: index + 1, title: step.title })
            : t("suite.forms.steps.numbered", { number: index + 1 }),
    })),
);

const sourceOptions = computed(() =>
    conditionSources.value.map((field) => ({ value: field.id, label: labelOf(field) })),
);

/** Les choix d'une question source, pour proposer la réponse plutôt que la faire taper. */
function sourceChoices(fieldId) {
    const source = conditionSources.value.find((field) => field.id === fieldId);
    const options = source?.translations?.[locales[0]]?.options ?? [];

    return options.map((option) => ({ value: option, label: option }));
}

const visibility = computed({
    get: () => (draft.value.conditions.length ? "conditional" : "always"),
    set: (value) => {
        if ("always" === value) {
            draft.value.conditions = [];
        } else if (!draft.value.conditions.length) {
            addCondition();
        }
    },
});

const visibilityOptions = computed(() => [
    { value: "always", label: t("suite.forms.fields.visibility_always") },
    { value: "conditional", label: t("suite.forms.fields.visibility_conditional") },
]);

const logicOptions = computed(() =>
    conditionLogics.map((logic) => ({ value: logic.value, label: t(`suite.forms.fields.logic_sentence.${logic.value}`) })),
);

function hasLabelIn(locale) {
    return "" !== (draft.value.translations[locale]?.label ?? "").trim();
}

/** Les erreurs que le serveur range ailleurs que sous un champ visible. */
const generalErrors = computed(() =>
    Object.entries(fieldErrors.value)
        .filter(([key]) => !key.startsWith("translations["))
        .map(([, message]) => message),
);

const placeholderHint = computed(() =>
    typeMeta.value?.hasOptions && "select" === draft.value.type
        ? t("suite.forms.fields.placeholder_hint_select")
        : t("suite.forms.fields.placeholder_hint"),
);

/** Les cases et les boutons radio n'ont pas de texte d'exemple : il n'y a nulle part où l'écrire. */
const offersPlaceholder = computed(() => !["checkbox", "radio"].includes(draft.value.type));
</script>

<template>
    <form class="space-y-4" v-on:submit.prevent="submitField">
        <div v-if="!bare" class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-2">
                <component :is="iconForType(draft.type)" class="h-4 w-4 shrink-0 text-accent-400" :stroke-width="2" />
                <h3 class="truncate text-sm font-semibold text-primary">
                    {{ t(editingField ? "suite.forms.fields.edit" : "suite.forms.fields.create") }}
                </h3>
            </div>
            <AppIconButton :title="t('shared.common.close')" v-on:click="closeEditor">
                <X class="h-4 w-4" :stroke-width="2" />
            </AppIconButton>
        </div>

        <AppMessage v-if="generalErrors.length" variant="danger">
            <p v-for="message in generalErrors" :key="message" class="m-0">{{ t(message, message) }}</p>
        </AppMessage>

        <AppSelect v-model="draft.type" :label="t('suite.forms.fields.type')" :options="typeOptions" />

        <!-- Une langue à la fois. Le point signale une langue sans libellé :
             la question y sortirait vide pour le visiteur. -->
        <div v-if="locales.length > 1" class="inline-flex gap-1 rounded-lg border border-line bg-surface-2 p-1">
            <AppTab
                v-for="code in locales"
                :key="code"
                size="sm"
                :active="editLocale === code"
                active-class="bg-surface text-primary shadow-sm"
                inactive-class="text-secondary hover:text-primary"
                v-on:click="editLocale = code"
            >
                {{ code.toUpperCase() }}
                <span
                    v-if="editLocale !== code && !hasLabelIn(code)"
                    class="ms-1 inline-block h-1.5 w-1.5 rounded-full bg-current opacity-50"
                    :title="t('suite.forms.fields.untranslated')"
                />
            </AppTab>
        </div>

        <div class="space-y-3">
            <AppInput
                v-model="draft.translations[editLocale].label"
                :label="t('suite.forms.fields.label')"
                :placeholder="t('suite.forms.fields.label_placeholder')"
                :error="fieldErrors[`translations[${editLocale}].label`] ?? ''"
            />
            <AppInput
                v-if="offersPlaceholder"
                v-model="draft.translations[editLocale].placeholder"
                :label="t('suite.forms.fields.placeholder')"
                :hint="placeholderHint"
            />
            <AppTextarea
                v-if="typeMeta?.hasOptions"
                v-model="draft.translations[editLocale].options"
                :label="t('suite.forms.fields.options')"
                :placeholder="t('suite.forms.fields.options_placeholder')"
                :hint="t('suite.forms.fields.options_hint')"
                :rows="4"
                :error="fieldErrors[`translations[${editLocale}].options`] ?? ''"
            />
        </div>

        <AppToggle
            v-model="draft.required"
            :label="t('suite.forms.fields.required')"
            :hint="t('suite.forms.fields.required_hint')"
        />

        <AppSelect
            v-if="hasSteps"
            v-model="draft.step"
            :label="t('suite.forms.fields.step')"
            :options="stepOptions"
        />

        <!-- La condition en phrase : « Afficher seulement si [question] vaut
             [réponse] ». Deux cases sans verbe entre elles laissaient deviner
             laquelle était la question et laquelle la réponse. -->
        <section class="space-y-3 border-t border-line/40 pt-4">
            <AppChoiceRow v-model="visibility" :label="t('suite.forms.fields.visibility')" :options="visibilityOptions" />

            <template v-if="'conditional' === visibility">
                <p v-if="!sourceOptions.length" class="m-0 text-xs text-muted">
                    {{ t("suite.forms.fields.no_condition_source") }}
                </p>

                <template v-else>
                    <AppChoiceRow
                        v-if="draft.conditions.length > 1"
                        v-model="draft.conditionsLogic"
                        :label="t('suite.forms.fields.logic_label')"
                        :options="logicOptions"
                    />

                    <div
                        v-for="(condition, index) in draft.conditions"
                        :key="index"
                        class="space-y-2 rounded-lg border border-line bg-surface-2/40 p-3"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-xs font-medium text-secondary">
                                {{ t("suite.forms.fields.condition_if", { number: index + 1 }) }}
                            </span>
                            <AppIconButton color="rose" :title="t('suite.forms.fields.remove_condition')" v-on:click="removeCondition(index)">
                                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" />
                            </AppIconButton>
                        </div>
                        <AppSelect
                            v-model="condition.fieldId"
                            :options="sourceOptions"
                            :placeholder="t('suite.forms.fields.condition_field')"
                        />
                        <span class="block text-xs text-muted">{{ t("suite.forms.fields.condition_equals") }}</span>
                        <AppSelect
                            v-if="sourceChoices(condition.fieldId).length"
                            v-model="condition.value"
                            :options="sourceChoices(condition.fieldId)"
                            :placeholder="t('suite.forms.fields.condition_value')"
                        />
                        <AppInput v-else v-model="condition.value" :placeholder="t('suite.forms.fields.condition_value')" />
                    </div>

                    <AppButton variant="ghost" size="sm" v-on:click="addCondition">
                        <Plus class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("suite.forms.fields.add_condition") }}
                    </AppButton>
                </template>
            </template>
        </section>

        <div class="flex flex-col-reverse gap-2 border-t border-line/40 pt-4 sm:flex-row sm:items-center sm:justify-between">
            <AppButton
                v-if="editingField"
                variant="ghost"
                size="md"
                class="w-full justify-center whitespace-nowrap text-rose-400 sm:w-auto"
                :title="t('suite.forms.fields.delete')"
                v-on:click="pendingFieldDelete = editingField"
            >
                <Trash2 class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.delete") }}
            </AppButton>
            <span v-else class="hidden sm:block" />
            <div class="flex flex-col-reverse gap-2 sm:flex-row">
                <AppButton variant="ghost" size="md" class="w-full justify-center sm:w-auto" v-on:click="closeEditor">
                    <X class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.cancel") }}
                </AppButton>
                <AppButton
                    variant="primary"
                    size="md"
                    class="w-full justify-center sm:w-auto"
                    :loading="fieldLoading"
                    v-on:click="submitField"
                >
                    <Save class="h-3.5 w-3.5" :stroke-width="2" /> {{ t("shared.common.save") }}
                </AppButton>
            </div>
        </div>
    </form>
</template>
