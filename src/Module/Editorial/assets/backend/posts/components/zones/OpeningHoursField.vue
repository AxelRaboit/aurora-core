<script setup>
/**
 * The week's opening hours, one line per day.
 *
 * Each line is typed freely and read back through parseRanges: an author
 * writes « 9h-12h, 14h-18h » the way it is written on a door, and the page
 * stores what it understood. A line it cannot read is left as typed until it
 * can, so a half-typed range is not wiped from under the cursor.
 */
import { reactive, watch } from "vue";
import { useI18n } from "vue-i18n";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import { WEEKDAYS } from "../../composables/usePostGrid.js";
import { formatRanges, parseRanges } from "./openingHours.js";

const props = defineProps({
    modelValue: { type: Object, default: () => ({}) },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const lines = reactive(Object.fromEntries(WEEKDAYS.map((day) => [day, formatRanges(props.modelValue?.[day])])));

watch(
    () => props.modelValue,
    (value) => {
        for (const day of WEEKDAYS) {
            // Rewritten only when what is stored no longer matches what is
            // typed: otherwise every keystroke would reformat the line.
            if (formatRanges(parseRanges(lines[day])) !== formatRanges(value?.[day])) {
                lines[day] = formatRanges(value?.[day]);
            }
        }
    },
);

function update(day, text) {
    lines[day] = text;
    emit("update:modelValue", { ...props.modelValue, [day]: parseRanges(text) });
}
</script>

<template>
    <div class="space-y-2">
        <p class="text-sm font-medium text-primary">{{ t("backend.posts.grid.hours_label") }}</p>
        <p class="text-xs text-muted">{{ t("backend.posts.grid.hours_hint") }}</p>
        <div class="grid grid-cols-[6rem_1fr] items-center gap-x-3 gap-y-2">
            <template v-for="day in WEEKDAYS" :key="day">
                <span class="text-sm text-secondary">{{ t(`backend.posts.grid.weekdays.${day}`) }}</span>
                <AppInput
                    :model-value="lines[day]"
                    :placeholder="t('backend.posts.grid.hours_placeholder')"
                    v-on:update:model-value="(value) => update(day, value)"
                />
            </template>
        </div>
    </div>
</template>
