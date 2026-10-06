<script setup>
import { defineAsyncComponent, useTemplateRef, watch } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
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
    /** Mounted by the back office preview: everything works except sending. */
    preview: { type: Boolean, default: false },
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
 * The same date picker as the back office, loaded on demand.
 *
 * **The native field read the date the wrong way.** A date field rendered by
 * the browser follows the visitor's machine, not the site's language: a
 * French form asked for `mm/dd/yyyy` on an American machine, and a date read
 * backwards is not an unreadable date, it is a wrong date. That is the same
 * reasoning that took native fields out of the back office, and a rule checks
 * it - which this file was getting around without meaning to, by building
 * the attribute on the fly rather than writing it.
 *
 * **Loaded dynamically** because it weighs two hundred kilobytes on its own,
 * its calendar and locales included: a contact form without a date does not
 * need to download them. Vite makes it a separate chunk, requested the day a
 * date field exists.
 */
const AppDatePicker = defineAsyncComponent(
    () => import("@/shared/components/form/picker/AppDatePicker.vue"),
);

/**
 * The house select, as everywhere else.
 *
 * The native menu was kept here on purpose, for the phone's wheel. It
 * followed neither the theme nor the site's font, the only control on the
 * page to clash with the rest, and on a phone this one opens no keyboard as
 * long as the list stays short. Loaded on demand like the date picker: a form
 * without a list does not need to download vue-multiselect.
 *
 * No native `required` any more: the server refuses an empty choice and the
 * error shows under the field, as for all the others.
 */
const AppSelect = defineAsyncComponent(
    () => import("@/shared/components/form/select/AppSelect.vue"),
);

/** A field's choices, in the { value, label } format AppSelect expects. */
function choices(field) {
    return (field.options ?? []).map((option) => ({ value: option, label: option }));
}

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

            <AppSelect
                v-else-if="field.type === 'select'"
                v-model="answers[String(field.id)]"
                :options="choices(field)"
                :placeholder="field.placeholder || t('frontend.editorial.forms.select_placeholder')"
                :required="field.required"
            />

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

        <!-- `flex-1` below `sm`, natural size above: with one step there is
             only one button and it takes the line, with two they share it in
             two halves. The rule follows what is there rather than a fixed
             case. `items-stretch` because "Précédent" has a border and its
             neighbour does not: centred, the two were a pixel apart. -->
        <div class="flex items-stretch gap-2">
            <AppButton
                v-if="steps?.length && stepIndex > 0"
                variant="secondary"
                class="flex-1 sm:flex-none"
                v-on:click="goToStep(stepIndex - 1)"
            >
                {{ t("frontend.editorial.forms.previous") }}
            </AppButton>

            <AppButton
                v-if="!isLastStep"
                class="flex-1 sm:flex-none"
                v-on:click="goToStep(stepIndex + 1)"
            >
                {{ t("frontend.editorial.forms.next") }}
            </AppButton>

            <AppButton
                v-else
                type="submit"
                class="flex-1 sm:flex-none"
                :loading="sending"
            >
                {{ t("frontend.editorial.forms.submit") }}
            </AppButton>
        </div>
    </form>
</template>
