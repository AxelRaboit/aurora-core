<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    /** Help text under the switch — explains what flipping it does. */
    hint: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <span v-if="label" class="text-xs text-muted uppercase tracking-wide">{{ label }}</span>
        <button
            type="button"
            role="switch"
            :aria-checked="modelValue"
            :disabled="disabled"
            class="relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent/50"
            :class="[modelValue ? 'bg-accent' : 'bg-surface-3', disabled ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer']"
            v-on:click="!disabled && $emit('update:modelValue', !modelValue)"
        >
            <span
                class="inline-block h-3.5 w-3.5 rounded-full bg-white shadow transition-transform"
                :class="modelValue ? 'translate-x-[18px]' : 'translate-x-0.5'"
            />
        </button>
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
    </div>
</template>
