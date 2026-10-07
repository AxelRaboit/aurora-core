<script setup>
import { VueDatePicker } from "@vuepic/vue-datepicker";
import "@vuepic/vue-datepicker/dist/main.css";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";
import { useTheme } from "@/shared/composables/useTheme.js";
import { useI18n } from "vue-i18n";
import { computed } from "vue";
import { fr, enUS, es, de } from "date-fns/locale";
import { fromDisplay, isKnownZone, offsetIn, toDisplay } from "@/shared/utils/format/zonedTime.js";

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
    /** Help text under the control - explains the field, unlike `error` which reports it. */
    hint: { type: String, default: "" },
    enableTime: { type: Boolean, default: false },
    /**
     * Month-only picker. `modelValue` is then expected/emitted as
     * `YYYY-MM` (e.g. `2026-05`) instead of a full ISO date - handy
     * for budget months, monthly reports, etc.
     */
    monthOnly: { type: Boolean, default: false },
    /**
     * The time zone in which the time is read and typed, with `enableTime`.
     *
     * Without it, the field follows the browser and returns a bare time
     * (`2026-10-02T09:00`), which the server interprets its own way. With it, a
     * value received with its offset is shown at the time of that zone, and the
     * field returns the typed time with the offset that applies to it
     * (`2026-10-02T09:00:00+02:00`): 9:00 means 9:00 in the site's time,
     * whatever the computer's setting.
     */
    timeZone: { type: String, default: "" },
});

const emit = defineEmits(["update:modelValue"]);

/**
 * The formats accepted from the keyboard, from the most common to the most
 * tolerated.
 *
 * The field looks like a text field, so people type in it. Before, a typed
 * date was shown and then disappeared when the calendar closed, without a
 * word: the component only listened to clicks. The first format is the one
 * the component returns, the next ones are what a person writes on their
 * own.
 *
 * Month only has its own: `2026-05` and `05/2026`.
 */
const TEXT_FORMATS = ["dd/MM/yyyy", "yyyy-MM-dd", "ddMMyyyy", "dd-MM-yyyy", "dd.MM.yyyy"];
const TIME_FORMATS = ["dd/MM/yyyy HH:mm", "yyyy-MM-dd HH:mm"];
const MONTH_FORMATS = ["MM/yyyy", "yyyy-MM"];

/**
 * The formats this field accepts, in order.
 *
 * One place for both uses: what is displayed is the first in the list, what
 * is accepted from the keyboard is the whole list.
 */
const acceptedFormats = computed(
    () => props.monthOnly ? MONTH_FORMATS : (props.enableTime ? TIME_FORMATS : TEXT_FORMATS),
);

/**
 * What the field displays, and not what the browser prefers.
 *
 * Without this line, `VueDatePicker` renders the date with its default
 * format, which follows the machine rather than the application: a French
 * back office showed `09/20/2026, 20:00` for 20 September. The docblock above
 * already announced that "the first format is the one the component
 * returns" - it was an intention, not an implementation.
 *
 * It is the same defect as the one that had the native `datetime-local`
 * fields replaced by this component: a date read the wrong way is not an
 * unreadable date, it is a date understood backwards.
 *
 * Passed through `formats.input`: since its version 12, the library no longer
 * reads `format`, and ignored it without a word (the whole back office again
 * showed `11/05/2026, 01:00` for 5 November).
 */
const displayFormat = computed(() => acceptedFormats.value[0]);

const textInput = computed(() => ({
    format: acceptedFormats.value,
    // Enter validates the input, and an input the component cannot read leaves
    // the previous value rather than emptying the field.
    enterSubmit: true,
    tabSubmit: true,
    openMenu: "open",
}));

const HAS_OFFSET = /(?:[zZ]|[+-]\d{2}:?\d{2})$/;

/** The requested time zone, if this browser knows it; otherwise the browser's own. */
const zone = computed(() => (isKnownZone(props.timeZone) ? props.timeZone : ""));

function onUpdate(value) {
    if (!value) { emit("update:modelValue", ""); return; }
    const pad = (number) => String(number).padStart(2, "0");
    if (props.monthOnly) {
        // VueDatePicker emits `{ month, year }` in month-picker mode.
        const year = value.year ?? new Date(value).getFullYear();
        const monthIndex = (value.month ?? new Date(value).getMonth());
        emit("update:modelValue", `${year}-${pad(monthIndex + 1)}`);
        return;
    }
    const date = new Date(value);
    if (props.enableTime && zone.value) {
        const wall = `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
        emit("update:modelValue", `${wall}:00${offsetIn(fromDisplay(date, zone.value), zone.value)}`);
    } else if (props.enableTime) {
        emit("update:modelValue", `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`);
    } else {
        emit("update:modelValue", `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`);
    }
}

const internalValue = computed(() => {
    if (!props.modelValue) return null;
    if (props.monthOnly) {
        // Accept either YYYY-MM or YYYY-MM-DD - pull year + month index back out
        // and feed VueDatePicker's `{ month, year }` shape.
        const match = /^(\d{4})-(\d{2})/.exec(props.modelValue);
        if (!match) return null;
        return { year: Number.parseInt(match[1], 10), month: Number.parseInt(match[2], 10) - 1 };
    }
    // A value that carries its offset is an instant: it is shown at the time of
    // the requested zone. A bare time is already a time in that zone.
    const value = zone.value && props.enableTime && HAS_OFFSET.test(props.modelValue)
        ? toDisplay(props.modelValue, zone.value)
        : props.modelValue;
    const date = new Date(value);
    return isNaN(date.getTime()) ? null : date;
});
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" :required="required" />
        <VueDatePicker
            :model-value="internalValue"
            :dark="isDark"
            :locale="dateFnsLocale"
            :formats="{ input: displayFormat }"
            :enable-time-picker="enableTime"
            :month-picker="monthOnly"
            :placeholder="placeholder"
            :text-input="textInput"
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
/* The calendar field, painted like AppInput.
 *
 * It is not rendered by us - the library sets its own `input` - so it
 * cannot carry the utility classes of the other fields, and these rules are
 * the literal translation of AppInput's. The focus ring was a hard-coded
 * indigo: on a site whose accent is not indigo, a single field of the form
 * lit up in the wrong colour.
 *
 * Any change to AppInput must go through here, or the two fields start
 * diverging again. */
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
.dp-custom-input::placeholder {
    color: var(--color-muted);
}
.dp-custom-input:focus {
    border-color: var(--color-accent-500);
    box-shadow: 0 0 0 1px var(--color-accent-500);
}
</style>
