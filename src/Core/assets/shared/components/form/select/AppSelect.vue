<script setup>
import { computed } from "vue";
import AppMultiselect from "@/shared/components/form/select/AppMultiselect.vue";

/**
 * The single choice of the back office, never a native `<select>`.
 *
 * The browser menu follows neither the theme nor the font, and it does not
 * look the same from one system to another: it was the only house control
 * that clashed with the rest of the screen. This one relies on the select of
 * `AppMultiselect`, without search as long as the list stays short.
 *
 * The contract of the old `<select>` is kept as it was, so that callers have
 * nothing to change: the emitted value is always a string (what
 * `$event.target.value` returned, and what `v-model.number` can convert),
 * and the placeholder remains an entry that can be chosen, the one that
 * goes back to "All …" in a filter bar.
 */
const props = defineProps({
    modelValue: { type: [String, Number, null], default: "" },
    label: { type: String, default: "" },
    error: { type: String, default: "" },
    /** Help text under the control - explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    required: { type: Boolean, default: false },
    /** Topic id from `helpTopics.js`, surfaced next to the label. */
    help: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    disabled: { type: Boolean, default: false },
    /** Array of { value, label } OR object { value: label }. */
    options: { type: [Array, Object], default: () => [] },
    /** Beyond this number of entries, typing filters the list. */
    searchAbove: { type: Number, default: 10 },
});

const emit = defineEmits(["update:modelValue"]);

const choices = computed(() => {
    const list = Array.isArray(props.options)
        ? props.options.map((option) => ({ value: String(option.value), label: option.label }))
        : Object.entries(props.options ?? {}).map(([value, label]) => ({ value, label }));

    return props.placeholder ? [{ value: "", label: props.placeholder }, ...list] : list;
});

const current = computed(() => String(props.modelValue ?? ""));
</script>

<template>
    <AppMultiselect
        :model-value="current"
        :options="choices"
        :label="label"
        :error="error"
        :hint="hint"
        :help="help"
        :required="required"
        :disabled="disabled"
        :placeholder="placeholder"
        :searchable="choices.length > searchAbove"
        v-on:update:model-value="emit('update:modelValue', $event ?? '')"
    />
</template>
