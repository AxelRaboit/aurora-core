<script setup>
import { useI18n } from "vue-i18n";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";

const { t } = useI18n();

defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    /**
     * Help text under the control - explains the field, unlike `error` which
     * reports it. Ignored by the `ghost` variant, which has no chrome to hang
     * it on.
     */
    hint: { type: String, default: '' },
    required: { type: Boolean, default: false },
    rows: { type: Number, default: 3 },
    mono: { type: Boolean, default: false },
    maxlength: { type: Number, default: null },
    /**
     * Visual flavor - same semantics as {@link AppInput.vue}. `ghost`
     * yields a transparent, label-less textarea that inherits the
     * parent's text color (useful for inline editing inside cards,
     * post-its, editable rows…). Sizing / decoration is the consumer's
     * job via the merged `class` attribute.
     */
    variant: { type: String, default: 'default' }, // default | ghost
});

defineEmits(['update:modelValue']);
</script>

<template>
    <textarea
        v-if="variant === 'ghost'"
        :value="modelValue"
        :placeholder="placeholder"
        :rows="rows"
        :maxlength="maxlength"
        class="block w-full bg-transparent border-0 focus:outline-none focus:ring-0 text-inherit resize-none"
        v-on:input="$emit('update:modelValue', $event.target.value)"
    />
    <div v-else class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <textarea
            :value="modelValue"
            :placeholder="placeholder"
            :rows="rows"
            :maxlength="maxlength"
            class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-primary placeholder-muted focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition resize-none"
            :class="[
                { 'border-red-500 focus:border-red-500 focus:ring-red-500': error },
                mono ? 'text-xs font-mono' : 'text-sm',
            ]"
            v-on:input="$emit('update:modelValue', $event.target.value)"
        />
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-500">{{ t(error, error) }}</p>
    </div>
</template>
