<script setup>
defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, default: '' },
    /**
     * Help text under the box. Sits outside the `<label>` so clicking it does
     * not toggle - a paragraph of explanation is meant to be read, not hit.
     */
    hint: { type: String, default: '' },
    name: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
});

defineEmits(['update:modelValue']);
</script>

<template>
    <div class="flex flex-col gap-1">
        <label class="flex items-center gap-2 text-sm text-secondary cursor-pointer select-none" :class="{ 'opacity-50 cursor-not-allowed': disabled }">
            <input
                type="checkbox"
                :name="name || undefined"
                :checked="modelValue"
                :disabled="disabled"
                class="w-4 h-4 rounded border border-line bg-surface text-white checked:bg-accent-600 checked:border-accent-600 focus:ring-2 focus:ring-accent-500 focus:ring-offset-0 transition-colors"
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
