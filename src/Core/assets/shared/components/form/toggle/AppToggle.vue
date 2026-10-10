<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    /** Help text under the switch - explains what flipping it does. */
    hint: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <span v-if="label" class="text-[0.8125rem] font-medium text-primary">{{ label }}</span>
        <!-- The button is the hit area, the track inside it is the drawing.
             On a phone the button grows through its padding to a finger's
             size while the track keeps its own: one element doing both drew
             its colour over the padding (a squashed disc), and clipping the
             colour to the content kept the corners rounded on the taller box
             (a cushion). -->
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :disabled="disabled"
            class="group relative inline-flex w-9 shrink-0 items-center rounded-full focus:outline-none py-[0.3125rem] -my-[0.3125rem] sm:py-0 sm:my-0"
            :class="disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'"
            v-on:click="!disabled && $emit('update:modelValue', !modelValue)"
        >
            <span
                data-track
                class="inline-flex h-5 w-9 items-center rounded-full transition-colors group-focus-visible:ring-2 group-focus-visible:ring-accent/50"
                :class="modelValue ? 'bg-accent' : 'bg-surface-3'"
            >
                <span
                    class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow transition-transform"
                    :class="modelValue ? 'translate-x-[18px]' : 'translate-x-0.5'"
                />
            </span>
        </button>
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
    </div>
</template>
