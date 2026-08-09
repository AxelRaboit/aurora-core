<script setup>
/**
 * AppAmountInput — decimal amount input with optional arithmetic evaluation
 * on blur. Type "100+50" then leave the field → value becomes "150.00".
 *
 * Inspired by Spendly's UX: type math expressions (+, -, *, /, parens) to
 * combine amounts without leaving the keyboard.
 *
 * Allowed input chars: digits, `.`, operators `+ - * /`, parens, spaces.
 * Other chars are stripped silently. The actual evaluation lives in the
 * shared utility `evaluateAmount.js` so callers (parent SFC) can also
 * normalize the value before submit — useful when the user clicks the
 * submit button without blurring the field first.
 *
 * Defines `evaluate()` via defineExpose so a parent can force evaluation
 * imperatively (e.g. `inputRef.value?.evaluate()` right before submit).
 */
import { ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";
import { evaluateAmount } from "@/shared/utils/form/amount/evaluateAmount.js";

const props = defineProps({
    modelValue: { type: String, default: "" },
    label: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    error: { type: String, default: "" },
    /** Help text under the control — explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    required: { type: Boolean, default: false },
    decimals: { type: Number, default: 2 },
    allowNegative: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const local = ref(String(props.modelValue ?? ""));

watch(
    () => props.modelValue,
    (v) => {
        const next = String(v ?? "");
        if (next !== local.value) local.value = next;
    },
);

function onInput(event) {
    const cleaned = event.target.value.replace(/[^0-9.+\-*/()\s]/g, "");
    local.value = cleaned;
    emit("update:modelValue", cleaned);
}

function evaluate() {
    const formatted = evaluateAmount(local.value, {
        decimals: props.decimals,
        allowNegative: props.allowNegative,
    });
    if (formatted !== local.value) {
        local.value = formatted;
        emit("update:modelValue", formatted);
    }
}

defineExpose({ evaluate });
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <input
            :value="local"
            :placeholder="placeholder"
            :required="required"
            type="text"
            inputmode="decimal"
            class="block w-full rounded-md border border-line bg-surface px-3 py-2 text-sm text-primary placeholder-muted focus:border-accent-500 focus:ring-1 focus:ring-accent-500 transition"
            :class="{ 'border-red-500 focus:border-red-500 focus:ring-red-500': error }"
            v-on:input="onInput"
            v-on:blur="evaluate"
        >
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-500">{{ t(error, error) }}</p>
    </div>
</template>
