<script setup>
/**
 * A number with its unit, written when it is complete.
 *
 * Emitted on every valid keystroke, but only once the field holds a number:
 * an empty field or a lone minus sign is a number being typed, and writing it
 * would move the element to zero for the length of a keystroke.
 */
import { ref, watch } from "vue";

const props = defineProps({
    modelValue: { type: Number, default: 0 },
    label: { type: String, default: "" },
    unit: { type: String, default: "" },
    min: { type: Number, default: null },
    max: { type: Number, default: null },
    step: { type: Number, default: 1 },
    disabled: { type: Boolean, default: false },
    /** What a value looks like, shown while the field is empty. */
    placeholder: { type: String, default: "0" },
});

const emit = defineEmits(["update:modelValue"]);

const shown = ref(format(props.modelValue));

function format(value) {
    return Number.isFinite(value) ? String(Math.round(value * 10) / 10) : "";
}

watch(
    () => props.modelValue,
    (value) => {
        if (Number(shown.value) !== value) shown.value = format(value);
    },
);

function onInput(event) {
    shown.value = event.target.value;

    const value = Number(event.target.value);

    if (event.target.value === "" || !Number.isFinite(value)) return;

    let bounded = value;
    if (props.min !== null) bounded = Math.max(props.min, bounded);
    if (props.max !== null) bounded = Math.min(props.max, bounded);

    emit("update:modelValue", bounded);
}
</script>

<template>
    <label class="flex min-w-0 flex-col gap-1">
        <span v-if="label" class="text-[0.65rem] uppercase tracking-wide text-muted">{{ label }}</span>
        <span class="flex items-center rounded-md border border-line bg-surface px-2 focus-within:border-accent-500">
            <input
                type="number"
                class="w-full min-w-0 border-0 bg-transparent py-1.5 text-sm tabular-nums text-primary outline-none"
                :value="shown"
                :min="min ?? undefined"
                :max="max ?? undefined"
                :step="step"
                :placeholder="placeholder"
                :disabled="disabled"
                v-on:input="onInput"
                v-on:blur="shown = format(modelValue)"
            >
            <span v-if="unit" class="shrink-0 pl-1 text-xs text-muted">{{ unit }}</span>
        </span>
    </label>
</template>
