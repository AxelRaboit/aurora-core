<script setup>
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";

const props = defineProps({
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    /** Help text under the control — explains the field, unlike `error` which reports it. */
    hint: { type: String, default: '' },
    required: { type: Boolean, default: false },
    placeholder: { type: String, default: '' },
    // Array of { value, label } OR object { value: label } — leave empty to use slot
    options: { type: [Array, Object], default: null },
});

defineEmits(['update:modelValue']);

const isObjectMap = (v) => v !== null && !Array.isArray(v) && typeof v === 'object';
const isArrayOpts = (v) => Array.isArray(v) && v.length > 0;
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <select
            :value="modelValue"
            class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition"
            :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500': error }"
            v-on:change="$emit('update:modelValue', $event.target.value)"
        >
            <option v-if="placeholder" value="">{{ placeholder }}</option>

            <template v-if="isArrayOpts(options)">
                <option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
            </template>

            <template v-else-if="isObjectMap(options)">
                <option v-for="(lbl, val) in options" :key="val" :value="val">{{ lbl }}</option>
            </template>

            <template v-else>
                <slot />
            </template>
        </select>
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
    </div>
</template>
