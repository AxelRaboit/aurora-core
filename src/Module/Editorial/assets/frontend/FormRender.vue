<script setup>
import { defineAsyncComponent, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import { useFormRender } from "./composables/useFormRender.js";

/**
 * A published form, as a visitor fills it in.
 *
 * Conditions are evaluated here so a field appears the moment the answer it
 * depends on changes - and again on the server, which is the copy that
 * decides what is stored. See useFormRender for why the two must agree.
 */
const props = defineProps({
    form: { type: Object, required: true },
    submitPath: { type: String, required: true },
    // Absent on a site with no check configured, which is the default: the
    // component then draws no box and sends no token, and the server accepts
    // the submission as it always did.
    captcha: { type: Object, default: () => ({ enabled: false }) },
});

const { t } = useI18n();

const {
    captcha, answers, errors, sending, sent, notice,
    steps, stepIndex, fieldsForStep, isLastStep, goToStep, submit,
} = useFormRender(props);

// Watched rather than drawn on mount: on a multi-step form the box only
// exists once the visitor reaches the last step, so at mount there is no node
// to render into. The watcher fires whenever one appears.
const captchaBox = useTemplateRef("captchaBox");

watch(captchaBox, (element) => {
    if (element) captcha.mount(element);
});

const inputClass =
    "w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary";

/**
 * Le même sélecteur de date que le back-office, chargé à la demande.
 *
 * **Le champ natif lisait la date de travers.** Un champ de type date rendu
 * par le navigateur suit la machine du visiteur, pas la langue du site : un
 * formulaire français demandait `mm/dd/yyyy` sur une machine américaine, et
 * une date comprise à l'envers n'est pas une date illisible, c'est une date
 * fausse. C'est déjà le raisonnement qui avait sorti les champs natifs du
 * back-office, et une règle le vérifie - que ce fichier contournait sans le
 * vouloir, en construisant l'attribut au vol plutôt qu'en l'écrivant.
 *
 * **Chargé dynamiquement** parce qu'il pèse deux cents kilos-octets à lui seul,
 * son calendrier et ses locales comprises : un formulaire de contact sans date
 * n'a pas à les télécharger. Vite en fait un morceau à part, demandé le jour
 * où un champ date existe.
 */
const AppDatePicker = defineAsyncComponent(
    () => import("@/shared/components/form/picker/AppDatePicker.vue"),
);

function inputType(type) {
    return { number: "number", tel: "tel", email: "email" }[type] ?? "text";
}
</script>

<template>
    <p v-if="sent" class="text-sm rounded-lg px-3 py-2 bg-surface-2 text-primary">
        {{ t("frontend.editorial.forms.sent") }}
    </p>

    <form v-else class="space-y-4" v-on:submit.prevent="submit">
        <p
            v-if="notice"
            class="text-sm rounded-lg px-3 py-2 bg-surface-2"
            :class="notice.type === 'error' ? 'text-rose-500' : 'text-primary'"
        >
            {{ notice.text }}
        </p>

        <ol v-if="steps?.length" class="flex flex-wrap gap-2 text-xs">
            <li
                v-for="(step, index) in steps"
                :key="index"
                class="px-2 py-1 rounded-md"
                :class="index === stepIndex ? 'bg-accent-600 text-white' : 'bg-surface-2 text-secondary'"
            >
                {{ step.title || t("frontend.editorial.forms.step", { number: index + 1 }) }}
            </li>
        </ol>

        <label v-for="field in fieldsForStep" :key="field.id" class="block space-y-1">
            <span class="text-xs text-secondary">
                {{ field.label }}<span v-if="field.required" aria-hidden="true"> *</span>
            </span>

            <textarea
                v-if="field.type === 'textarea'"
                v-model="answers[String(field.id)]"
                rows="4"
                :placeholder="field.placeholder ?? ''"
                :required="field.required"
                :class="inputClass"
            />

            <select
                v-else-if="field.type === 'select'"
                v-model="answers[String(field.id)]"
                :required="field.required"
                :class="inputClass"
            >
                <option value="">{{ field.placeholder ?? "" }}</option>
                <option v-for="option in field.options" :key="option" :value="option">{{ option }}</option>
            </select>

            <span v-else-if="field.type === 'radio'" class="block space-y-1">
                <label v-for="option in field.options" :key="option" class="flex items-center gap-2 text-sm text-primary">
                    <input
                        v-model="answers[String(field.id)]"
                        type="radio"
                        :value="option"
                        :name="`field-${field.id}`"
                    >
                    {{ option }}
                </label>
            </span>

            <span v-else-if="field.type === 'checkbox'" class="block space-y-1">
                <label v-for="option in field.options" :key="option" class="flex items-center gap-2 text-sm text-primary">
                    <input v-model="answers[String(field.id)]" type="checkbox" :value="option">
                    {{ option }}
                </label>
            </span>

            <AppDatePicker
                v-else-if="field.type === 'date'"
                v-model="answers[String(field.id)]"
                :placeholder="field.placeholder ?? ''"
                :required="field.required"
            />

            <input
                v-else
                v-model="answers[String(field.id)]"
                :type="inputType(field.type)"
                :placeholder="field.placeholder ?? ''"
                :required="field.required"
                :class="inputClass"
            >

            <span v-if="errors[String(field.id)]" class="block text-xs text-rose-500">
                {{ errors[String(field.id)] }}
            </span>
        </label>

        <!-- On the last step only: a widget on step one is answered, then
             expires while the visitor is still filling in step three. -->
        <div v-if="captcha.enabled && isLastStep" ref="captchaBox" class="min-h-0" />

        <!-- `flex-1` sous `sm`, taille naturelle au-dessus : à une étape il n'y
             a qu'un bouton et il prend la ligne, à deux ils se la partagent en
             deux moitiés. La règle suit ce qui est là plutôt qu'un cas fixe. -->
        <div class="flex items-center gap-2">
            <button
                v-if="steps?.length && stepIndex > 0"
                type="button"
                class="flex-1 sm:flex-none px-4 py-2 rounded-lg border border-line text-sm text-secondary"
                v-on:click="goToStep(stepIndex - 1)"
            >
                {{ t("frontend.editorial.forms.previous") }}
            </button>

            <button
                v-if="!isLastStep"
                type="button"
                class="flex-1 sm:flex-none px-4 py-2 rounded-lg bg-accent-600 text-white text-sm font-medium"
                v-on:click="goToStep(stepIndex + 1)"
            >
                {{ t("frontend.editorial.forms.next") }}
            </button>

            <button
                v-else
                type="submit"
                class="flex-1 sm:flex-none px-4 py-2 rounded-lg bg-accent-600 text-white text-sm font-medium disabled:opacity-60"
                :disabled="sending"
            >
                {{ t("frontend.editorial.forms.submit") }}
            </button>
        </div>
    </form>
</template>
