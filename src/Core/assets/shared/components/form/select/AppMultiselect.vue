<script setup>
import { computed } from "vue";
import Multiselect from "vue-multiselect";
import { useI18n } from "vue-i18n";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";

const { t } = useI18n();

const props = defineProps({
    modelValue: { type: [String, Number, Array, Object, null], default: null },
    options: { type: Array, default: () => [] },
    label: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    error: { type: String, default: "" },
    /** Help text under the control - explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    required: { type: Boolean, default: false },
    /** Topic id from `helpTopics.js`, surfaced next to the label. */
    help: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
    multiple: { type: Boolean, default: false },
    searchable: { type: Boolean, default: true },
    allowEmpty: { type: Boolean, default: false },
    trackBy: { type: String, default: "value" },
    optionLabel: { type: String, default: "label" },
    openDirection: { type: String, default: "bottom" },
    useTeleport: { type: Boolean, default: true },
});

/**
 * `open` and `close` are relayed because nothing else can see them.
 *
 * Listeners set on this component land on its root `<div>`, not on the
 * select it wraps, and the panel of that select is teleported into the
 * `body`: a caller that wants to know when the list closes - to collapse the
 * surrounding control, for example - has neither the event nor a `focusout`
 * that tells the truth.
 */
const emit = defineEmits(["update:modelValue", "open", "close"]);

const selectedOption = computed(() => {
    if (props.multiple) {
        if (!Array.isArray(props.modelValue)) return [];
        return props.options.filter((opt) => props.modelValue.includes(opt[props.trackBy]));
    }
    return props.options.find((opt) => opt[props.trackBy] === props.modelValue) ?? null;
});

function onSelect(value) {
    if (props.multiple) {
        emit("update:modelValue", (value ?? []).map((opt) => opt[props.trackBy]));
        return;
    }
    emit("update:modelValue", value ? value[props.trackBy] : null);
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" :help="help" />
        <Multiselect
            :model-value="selectedOption"
            :options="options"
            :label="optionLabel"
            :track-by="trackBy"
            :multiple="multiple"
            :searchable="searchable"
            :disabled="disabled"
            :allow-empty="allowEmpty"
            :open-direction="openDirection"
            :placeholder="placeholder || t('shared.common.select_placeholder')"
            :use-teleport="useTeleport"
            select-label=""
            selected-label=""
            deselect-label=""
            :class="{ 'multiselect--error': error }"
            v-on:update:model-value="onSelect"
            v-on:open="emit('open')"
            v-on:close="emit('close')"
        >
            <template #noOptions>{{ t('shared.common.no_options') }}</template>
            <template #noResult>{{ t('shared.common.no_result') }}</template>
        </Multiselect>
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
    </div>
</template>

<style src="vue-multiselect/dist/vue-multiselect.css"></style>

<style>
/* Global (not scoped) - dropdown is teleported to <body>, outside the component's DOM scope */
.multiselect__tags {
    background-color: var(--color-surface);
    border-color: var(--color-line);
    color: var(--color-primary);
    border-radius: 0.375rem;
    min-height: 38px;
    padding: 6px 40px 0 8px;
    font-size: 0.875rem;
}
/* The placeholder and the chosen value on the same line: the library gives
   the placeholder its own top padding, and « Tous les clients » sat two
   pixels above « En attente de revue » in the next field (UI audit of
   07/10/2026). One line height for both, and no top padding. */
.multiselect__single,
.multiselect__input,
.multiselect__placeholder {
    display: block;
    font-size: 0.875rem;
    line-height: 24px;
    min-height: 24px;
    margin-bottom: 6px;
    padding: 0 0 0 4px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.multiselect__single,
.multiselect__input {
    background-color: var(--color-surface);
    color: var(--color-primary);
}
.multiselect__placeholder {
    color: var(--color-muted);
}
.multiselect__content-wrapper {
    background-color: var(--color-surface);
    border-color: var(--color-line);
}
.multiselect__option {
    color: var(--color-primary);
    font-size: 0.875rem;
    background-color: var(--color-surface);
}
/* The suite's accent, not a fixed indigo: the list lit up in a colour that
   appeared nowhere else on screen. */
.multiselect__option--highlight {
    background: var(--color-accent-600);
    color: #fff;
}
.multiselect__option--selected {
    background: var(--color-surface-2);
    color: var(--color-primary);
    font-weight: 600;
}
.multiselect__option--selected.multiselect__option--highlight {
    background: var(--color-accent-700);
    color: #fff;
}
.multiselect__tag {
    background: var(--color-accent-600);
}
.multiselect--active .multiselect__tags {
    border-color: var(--color-accent-500);
    box-shadow: 0 0 0 1px var(--color-accent-500);
}
.multiselect--error .multiselect__tags {
    border-color: rgb(239 68 68);
}

/* On the public site (forms), the colours of the site theme rather than the
   back office indigo: the hover follows `highlight`, like every hover of the
   site, and the corner radius follows the other fields of the form. The list
   is teleported into the `body`, hence the class set on it. */
.aurora-front .multiselect__tags {
    border-radius: 0.5rem;
}
.aurora-front .multiselect__option--highlight,
.aurora-front .multiselect__option--selected.multiselect__option--highlight {
    background: var(--th-highlight, var(--th-accent-500));
    color: #fff;
}
.aurora-front .multiselect--active .multiselect__tags {
    border-color: var(--th-highlight, var(--th-accent-500));
    box-shadow: 0 0 0 1px var(--th-highlight, var(--th-accent-500));
}
</style>
