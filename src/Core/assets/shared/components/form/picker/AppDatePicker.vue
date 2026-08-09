<script setup>
import { VueDatePicker } from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";
import { useTheme } from "@/shared/composables/useTheme.js";
import { useI18n } from "vue-i18n";
import { computed } from "vue";
import { fr, enUS, es, de } from "date-fns/locale";

const { theme } = useTheme();
const { locale } = useI18n();
const isDark = computed(() => theme.value === "dark");

const LOCALES = { fr, en: enUS, es, de };
const dateFnsLocale = computed(() => LOCALES[locale.value] ?? enUS);

const props = defineProps({
    modelValue: { type: String, default: "" },
    label: { type: String, default: "" },
    placeholder: { type: String, default: "" },
    required: { type: Boolean, default: false },
    error: { type: String, default: "" },
    /** Help text under the control — explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    enableTime: { type: Boolean, default: false },
    /**
     * Month-only picker. `modelValue` is then expected/emitted as
     * `YYYY-MM` (e.g. `2026-05`) instead of a full ISO date — handy
     * for budget months, monthly reports, etc.
     */
    monthOnly: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

function onUpdate(val) {
    if (!val) { emit("update:modelValue", ""); return; }
    const pad = (n) => String(n).padStart(2, "0");
    if (props.monthOnly) {
        // VueDatePicker emits `{ month, year }` in month-picker mode.
        const year = val.year ?? new Date(val).getFullYear();
        const monthIndex = (val.month ?? new Date(val).getMonth());
        emit("update:modelValue", `${year}-${pad(monthIndex + 1)}`);
        return;
    }
    const d = new Date(val);
    if (props.enableTime) {
        emit("update:modelValue", `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`);
    } else {
        emit("update:modelValue", `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`);
    }
}

const internalValue = computed(() => {
    if (!props.modelValue) return null;
    if (props.monthOnly) {
        // Accept either YYYY-MM or YYYY-MM-DD — pull year + month index back out
        // and feed VueDatePicker's `{ month, year }` shape.
        const match = /^(\d{4})-(\d{2})/.exec(props.modelValue);
        if (!match) return null;
        return { year: Number.parseInt(match[1], 10), month: Number.parseInt(match[2], 10) - 1 };
    }
    const d = new Date(props.modelValue);
    return isNaN(d.getTime()) ? null : d;
});
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <VueDatePicker
            :model-value="internalValue"
            :dark="isDark"
            :locale="dateFnsLocale"
            :enable-time-picker="enableTime"
            :month-picker="monthOnly"
            :placeholder="placeholder"
            auto-apply
            :teleport="true"
            input-class-name="dp-custom-input"
            v-on:update:model-value="onUpdate"
        />
        <p v-if="hint" class="text-xs text-muted">{{ hint }}</p>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
    </div>
</template>

<style>
.dp-custom-input {
    width: 100%;
    border-radius: 0.375rem;
    border: 1px solid var(--color-line);
    background: var(--color-surface);
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    color: var(--color-primary);
    transition: border-color 0.15s, box-shadow 0.15s;
    outline: none;
}
.dp-custom-input:focus {
    border-color: #6366f1;
    box-shadow: 0 0 0 1px #6366f1;
}
</style>
