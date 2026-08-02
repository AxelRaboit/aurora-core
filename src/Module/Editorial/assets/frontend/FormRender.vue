<script setup>
import { useI18n } from "vue-i18n";
import { useFormRender } from "./composables/useFormRender.js";

/**
 * A published form, as a visitor fills it in.
 *
 * Conditions are evaluated here so a field appears the moment the answer it
 * depends on changes — and again on the server, which is the copy that
 * decides what is stored. See useFormRender for why the two must agree.
 */
const props = defineProps({
    form: { type: Object, required: true },
    submitPath: { type: String, required: true },
});

const { t } = useI18n();

const {
    answers, errors, sending, sent, notice,
    steps, stepIndex, fieldsForStep, isLastStep, goToStep, submit,
} = useFormRender(props);

const inputClass =
    "w-full rounded-lg border border-line bg-surface px-3 py-2 text-sm text-primary";

function inputType(type) {
    return { number: "number", date: "date", tel: "tel", email: "email" }[type] ?? "text";
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

        <div class="flex items-center gap-2">
            <button
                v-if="steps?.length && stepIndex > 0"
                type="button"
                class="px-4 py-2 rounded-lg border border-line text-sm text-secondary"
                v-on:click="goToStep(stepIndex - 1)"
            >
                {{ t("frontend.editorial.forms.previous") }}
            </button>

            <button
                v-if="!isLastStep"
                type="button"
                class="px-4 py-2 rounded-lg bg-accent-600 text-white text-sm font-medium"
                v-on:click="goToStep(stepIndex + 1)"
            >
                {{ t("frontend.editorial.forms.next") }}
            </button>

            <button
                v-else
                type="submit"
                class="px-4 py-2 rounded-lg bg-accent-600 text-white text-sm font-medium disabled:opacity-60"
                :disabled="sending"
            >
                {{ t("frontend.editorial.forms.submit") }}
            </button>
        </div>
    </form>
</template>
