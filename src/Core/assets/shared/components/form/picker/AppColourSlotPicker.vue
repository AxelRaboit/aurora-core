<script setup>
/**
 * The shared palette, as swatches.
 *
 * Swatches and not a select: the thing being chosen is the colour itself, so
 * showing it beats naming it - and these are the very tokens the charts, the
 * calendar and the boards draw with, so what is picked here is literally what
 * will appear.
 *
 * `clearable` offers a ninth, empty swatch. It exists because "no colour" is a
 * real answer for a board's steps - one where every step is coloured is one
 * where colour has stopped meaning anything - and a picker that cannot express
 * it forces a colour onto things that did not want one.
 */
import { useI18n } from "vue-i18n";
import { Ban } from "lucide-vue-next";
import AppFieldLabel from "@/shared/components/form/AppFieldLabel.vue";
import { COLOUR_SLOTS } from "@/shared/composables/chart/paletteSlots.js";

defineProps({
    modelValue: { type: [Number, String], default: null },
    label: { type: String, default: "" },
    /** Help text under the swatches - explains the field, unlike `error`. */
    hint: { type: String, default: "" },
    error: { type: String, default: "" },
    /** Offers "no colour" as a choice of its own. */
    clearable: { type: Boolean, default: false },
});

const emit = defineEmits(["update:modelValue"]);

const { t } = useI18n();

function isChosen(current, slot) {
    return Number(current) === slot;
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <AppFieldLabel :label="label" />
        <div class="flex flex-wrap items-center gap-2">
            <button
                v-if="clearable"
                type="button"
                class="flex h-7 w-7 items-center justify-center rounded-lg border-2 text-muted transition-transform hover:scale-110"
                :class="
                    modelValue === null || modelValue === ''
                        ? 'border-primary scale-110'
                        : 'border-line'
                "
                :aria-label="t('shared.palette.no_colour')"
                :aria-pressed="modelValue === null || modelValue === ''"
                v-on:click="emit('update:modelValue', null)"
            >
                <Ban class="h-3.5 w-3.5" :stroke-width="2" />
            </button>
            <button
                v-for="slot in COLOUR_SLOTS"
                :key="slot"
                type="button"
                class="h-7 w-7 rounded-lg border-2 transition-transform hover:scale-110"
                :class="
                    isChosen(modelValue, slot)
                        ? 'border-primary scale-110'
                        : 'border-transparent'
                "
                :style="{ backgroundColor: `var(--chart-cat-${slot})` }"
                :aria-label="t('shared.palette.colour_slot', { slot })"
                :aria-pressed="isChosen(modelValue, slot)"
                v-on:click="emit('update:modelValue', slot)"
            />
        </div>
        <p v-if="error" class="text-xs text-red-500">{{ error }}</p>
        <p v-else-if="hint" class="text-xs text-muted">{{ hint }}</p>
    </div>
</template>
