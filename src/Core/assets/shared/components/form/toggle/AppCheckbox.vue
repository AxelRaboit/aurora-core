<script setup>
/**
 * The suite's one checkbox.
 *
 * Tables had their own raw `<input type="checkbox">` with square corners,
 * forms this one with rounded ones: two drawings of the same control on the
 * same screen (UI audit of 07/10/2026). Selection in a table goes through here
 * too, with `ariaLabel` for a box that has no visible label and
 * `indeterminate` for « select all » when only part of the page is ticked.
 * `CheckboxesAreTheHouseOnesTest` keeps a raw one from coming back.
 */
import { onMounted, ref, watch } from "vue";

const props = defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    /**
     * Help text under the box. Sits outside the `<label>` so clicking it does
     * not toggle - a paragraph of explanation is meant to be read, not hit.
     */
    hint: { type: String, default: '' },
    name: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    /** Names a box that has no visible label, in a table row. */
    ariaLabel: { type: String, default: '' },
    /** Part of what it stands for is ticked: « select all » over a partial selection. */
    indeterminate: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);

// `indeterminate` is a property, not an attribute: it has to be set by hand.
const input = ref(null);
onMounted(() => {
    if (input.value) input.value.indeterminate = props.indeterminate;
});
watch(() => props.indeterminate, (value) => {
    if (input.value) input.value.indeterminate = value;
});
</script>

<template>
    <div class="flex flex-col gap-1">
        <label class="flex items-center gap-2 text-sm text-secondary cursor-pointer select-none" :class="{ 'opacity-50 cursor-not-allowed': disabled }">
            <input
                ref="input"
                type="checkbox"
                :name="name || undefined"
                :aria-label="ariaLabel || undefined"
                :checked="modelValue"
                :disabled="disabled"
                class="w-4 h-4 shrink-0 cursor-pointer rounded border border-line bg-surface text-white accent-accent-600 checked:bg-accent-600 checked:border-accent-600 indeterminate:bg-accent-600 indeterminate:border-accent-600 focus:ring-2 focus:ring-accent-500 focus:ring-offset-0 transition-colors"
                v-on:change="$emit('update:modelValue', $event.target.checked)"
            >
            <span v-if="label || $slots.default">
                <slot>{{ label }}</slot>
            </span>
        </label>
        <!-- Indented to the label text rather than the box: the hint belongs to
             what the box is called, not to the box. -->
        <p v-if="hint" class="pl-6 text-xs text-muted">{{ hint }}</p>
    </div>
</template>
