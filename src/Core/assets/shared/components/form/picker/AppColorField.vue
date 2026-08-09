<script setup>
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";
import AppColorSwatch from "@/shared/components/form/picker/AppColorSwatch.vue";

defineProps({
    modelValue: { type: String, default: "" },
    label: { type: String, default: "" },
    required: { type: Boolean, default: false },
    error: { type: String, default: "" },
    /** Help text under the control — explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    showHex: { type: Boolean, default: true },
    size: { type: String, default: "md" },
});

defineEmits(["update:modelValue"]);
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <div class="flex items-center gap-3">
            <AppColorSwatch
                :model-value="modelValue"
                :size="size"
                v-on:update:model-value="$emit('update:modelValue', $event)"
            />
            <span v-if="showHex && modelValue" class="text-sm text-secondary font-mono">{{ modelValue }}</span>
        </div>
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-rose-400">{{ error }}</p>
    </div>
</template>
