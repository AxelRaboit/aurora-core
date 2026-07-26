<script setup>
import { ref, computed } from "vue";
import { useI18n } from "vue-i18n";
import { Eye, EyeOff } from "lucide-vue-next";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";

const props = defineProps({
    modelValue: { type: String, default: '' },
    type: { type: String, default: 'text' },
    name: { type: String, default: '' },
    placeholder: { type: String, default: '' },
    label: { type: String, default: '' },
    error: { type: String, default: '' },
    required: { type: Boolean, default: false },
    readonly: { type: Boolean, default: false },
    toggleable: { type: Boolean, default: false },
    /**
     * Visual flavor. `default` ships the full form-field chrome (border,
     * surface background, label slot, error message). `ghost` strips
     * everything to a transparent, label-less input that inherits its
     * parent's text color — for inline editing inside cards, post-its,
     * editable table cells, etc. The consumer is expected to size /
     * decorate it via the merged `class` attribute.
     */
    variant: { type: String, default: 'default' }, // default | ghost
});

defineEmits(['update:modelValue']);

const { t } = useI18n();

const revealed = ref(false);
const inputType = computed(() => {
    if (props.toggleable) return revealed.value ? 'text' : 'password';
    return props.type;
});

const inputEl = ref(null);
defineExpose({
    focus: () => inputEl.value?.focus(),
    select: () => inputEl.value?.select(),
    blur: () => inputEl.value?.blur(),
});
</script>

<template>
    <input
        v-if="variant === 'ghost'"
        ref="inputEl"
        :type="inputType"
        :name="name || undefined"
        :value="modelValue"
        :placeholder="placeholder"
        :required="required"
        :readonly="readonly"
        class="block w-full bg-transparent border-0 focus:outline-none focus:ring-0 text-inherit"
        :class="readonly ? 'cursor-not-allowed opacity-70' : ''"
        v-on:input="$emit('update:modelValue', $event.target.value)"
    >
    <div v-else class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <div class="relative">
            <div v-if="$slots.prefix" class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3 text-muted">
                <slot name="prefix" />
            </div>
            <input
                ref="inputEl"
                :type="inputType"
                :name="name || undefined"
                :value="modelValue"
                :placeholder="placeholder"
                :required="required"
                :readonly="readonly"
                class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary placeholder-muted focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition"
                :class="[{ 'border-red-500 focus:border-red-500 focus:ring-red-500': error }, readonly ? 'bg-surface-2 text-secondary cursor-not-allowed' : '', (toggleable || $slots.suffix) ? 'pr-10' : '', $slots.prefix ? 'pl-8' : '']"
                v-on:input="$emit('update:modelValue', $event.target.value)"
            >
            <button
                v-if="toggleable"
                type="button"
                class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted hover:text-secondary transition"
                v-on:click="revealed = !revealed"
            >
                <Eye v-if="!revealed" class="w-4 h-4" :stroke-width="2" />
                <EyeOff v-else class="w-4 h-4" :stroke-width="2" />
            </button>
            <div v-else-if="$slots.suffix" class="absolute inset-y-0 right-0 flex items-center pr-3 text-muted">
                <slot name="suffix" />
            </div>
        </div>
        <p v-if="error" class="text-xs text-red-500">{{ t(error, error) }}</p>
    </div>
</template>
