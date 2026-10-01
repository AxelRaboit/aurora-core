<script setup>
/**
 * A colour for an element: one of the deck's three first, then any other.
 *
 * **The deck's own colours are offered by name.** An element painted `accent`
 * follows the deck the day its palette changes, which is what keeps a deck
 * made of free slides looking like one deck; a hexadecimal copied from the
 * accent that day would not. The three come first, so they are the easy pick.
 *
 * Any other colour is a swatch and a transparency slider, stored together as
 * `#rrggbbaa`: a veil of black at forty per cent over a photograph is a colour
 * like any other.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { X } from "lucide-vue-next";
import AppColorSwatch from "@/shared/components/form/picker/AppColorSwatch.vue";
import AppRange from "@/shared/components/form/toggle/AppRange.vue";

const props = defineProps({
    modelValue: { type: String, default: null },
    label: { type: String, default: "" },
    /** The deck's resolved colours, to draw the three named swatches. */
    appearance: { type: Object, default: null },
    /** Whether "none" is a choice. */
    clearable: { type: Boolean, default: false },
    /** Whether the transparency slider is offered. */
    alpha: { type: Boolean, default: true },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

const THEME = ["ink", "accent", "background"];

const isTheme = computed(() => THEME.includes(props.modelValue));

/** The swatch's own value: the six digits, whatever the stored form. */
const hex = computed(() => {
    const value = props.modelValue;

    if (!value) return "#000000";
    if (isTheme.value) return (props.appearance?.[value] ?? "#000000").slice(0, 7);

    return value.slice(0, 7);
});

/** The transparency, in per cent: the last two digits when there are eight. */
const opacity = computed(() => {
    const value = props.modelValue;

    if (!value || isTheme.value || value.length !== 9) return 100;

    return Math.round((parseInt(value.slice(7, 9), 16) / 255) * 100);
});

function compose(base, percent) {
    if (percent >= 100) return base;

    const byte = Math.round((Math.max(0, percent) / 100) * 255)
        .toString(16)
        .padStart(2, "0");

    return `${base}${byte}`;
}

const pickHex = (value) => emit("update:modelValue", compose(value.toLowerCase(), opacity.value));
const pickOpacity = (value) => emit("update:modelValue", compose(hex.value, value));
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <span v-if="label" class="text-xs font-medium uppercase tracking-wide text-secondary">{{ label }}</span>
        <div class="flex flex-wrap items-center gap-1.5">
            <button
                v-for="name in THEME"
                :key="name"
                type="button"
                class="h-7 w-7 cursor-pointer rounded-full border-2 p-0"
                :class="modelValue === name ? 'border-accent-500' : 'border-line'"
                :style="{ background: appearance?.[name] ?? '#888' }"
                :title="t(`backend.studio.decks.free.theme_colours.${name}`)"
                v-on:click="emit('update:modelValue', name)"
            />
            <span class="mx-1 h-5 w-px bg-line" />
            <AppColorSwatch :model-value="hex" size="sm" v-on:update:model-value="pickHex" />
            <button
                v-if="clearable && modelValue"
                type="button"
                class="inline-flex h-7 w-7 cursor-pointer items-center justify-center rounded-md border-0 bg-transparent text-muted hover:text-primary"
                :title="t('backend.studio.decks.free.no_colour')"
                v-on:click="emit('update:modelValue', null)"
            >
                <X class="h-3.5 w-3.5" :stroke-width="2" />
            </button>
        </div>
        <div v-if="alpha && modelValue && !isTheme" class="flex items-center gap-2">
            <AppRange
                :model-value="opacity"
                :min="0"
                :max="100"
                :step="5"
                v-on:update:model-value="pickOpacity"
            />
            <span class="w-10 shrink-0 text-right text-xs tabular-nums text-muted">{{ opacity }} %</span>
        </div>
    </div>
</template>
