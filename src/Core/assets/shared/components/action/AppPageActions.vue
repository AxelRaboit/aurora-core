<script setup>
/**
 * Everything a page offers, behind a single button.
 *
 * The same answer as {@see AppRowActions}, one storey up. A full-page editor
 * grows a button per capability - present, print, share, revisions, preview -
 * until the header is a wall of them that cannot fit on a phone. What stays out
 * of the sheet is the one thing the page is for (Save, Publish, Create) and the
 * way back to the list; navigation is not an action, and burying the primary
 * verb behind a click is the mistake this component is easiest to make with.
 *
 * The trigger says the word "Actions" rather than showing three dots. In a table
 * the column header names them; here nothing does, and a lone glyph in a row of
 * labelled buttons reads as a fourth mystery.
 *
 * **`iconOnlyOnPhone` makes the word go below `sm`**, and it is reserved for
 * header bars that fit on one line. The house rule since 18/09/2026: a label
 * that costs the line becomes an icon. In a bar of three commands at 375
 * pixels, "Actions" and "Save" spelled out take the room of the rest,
 * and since the neighbours are icons too, the lone glyph is no longer the
 * odd one out.
 *
 * **Why an option and not the rule everywhere**: the other mobile house rule
 * wants a button to take the whole line below `sm`. A full-width button whose
 * label is removed becomes an empty bar with three dots in the middle - tried
 * on the user list, it is worse than what it was fixing. The two rules do not
 * contradict each other, they answer two situations: a bar that stays
 * horizontal tightens, a command that takes the line keeps its name.
 *
 * `busy` is for the moment after the sheet has closed: the action is running,
 * the button that started it is out of sight, and the trigger carries the
 * spinner in its place. Per-action `loading` still shows on the row itself, for
 * a reader who opens the sheet again while it works.
 *
 * **An action marked `primary: true` leaves the sheet** and stands beside it
 * as the page's main button: « + Nouvelle publication », not « Actions » that
 * opens a modal holding that one line. Every list used to put its create verb
 * in the sheet, which made the most frequent gesture of the suite cost two
 * clicks through a centred modal (UI audit of 07/10/2026). When nothing else
 * is left, there is no « Actions » button at all.
 *
 * See {@see AppActionSheet} for the shape of an action.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { MoreHorizontal } from "lucide-vue-next";
import AppButton from "./AppButton.vue";
import AppActionSheet from "./AppActionSheet.vue";

const props = defineProps({
    /** What the page offers, in the order they are meant to be read. */
    actions: { type: Array, required: true },
    /** Names what is being acted on, in the sheet's title. Optional. */
    label: { type: String, default: "" },
    /** An action started from this sheet is still running. */
    busy: { type: Boolean, default: false },
    /** Mirrors AppButton, so the trigger sits at the weight the header needs. */
    variant: { type: String, default: "secondary" },
    size: { type: String, default: "md" },
    /** Header bar that fits on one line: the word goes below `sm`. */
    iconOnlyOnPhone: { type: Boolean, default: false },
    /**
     * A bar made of icons at every width, like the note editor's: there the
     * word was the only one, and it read as the odd one out (08/10/2026).
     * The word stays the button's name and its tooltip.
     */
    iconOnly: { type: Boolean, default: false },
});

// AppActionSheet has two roots (the trigger and its modal): a `class` set on
// this component landed nowhere, neither `w-full sm:w-auto` nor the others
// (14 calls). It now goes on the row that holds the buttons, which is what
// you see.
defineOptions({ inheritAttrs: false });

const { t } = useI18n();

const primaryActions = computed(() => props.actions.filter((action) => action.primary));
const sheetActions = computed(() => props.actions.filter((action) => !action.primary));

function runPrimary(action) {
    if (action.disabled || action.loading) return;
    action.onSelect?.();
}
</script>

<template>
    <div v-bind="$attrs" class="flex items-center gap-2">
        <AppActionSheet v-if="sheetActions.length" :actions="sheetActions" :label="label">
            <template #trigger="{ open }">
                <!-- Beside a main button it shrinks to its icon on a phone
                     and keeps its square: a full-width bar with three dots in
                     the middle reads as nothing. -->
                <AppButton
                    :class="primaryActions.length || iconOnly ? 'shrink-0' : 'flex-1 sm:flex-none'"
                    :variant="variant"
                    :size="size"
                    :loading="busy"
                    :label="t('shared.actions.plain_title')"
                    :icon-only="iconOnly"
                    :icon-only-on-phone="iconOnlyOnPhone || primaryActions.length > 0"
                    :title="t('shared.actions.plain_title')"
                    v-on:click="open"
                >
                    <MoreHorizontal v-if="!busy" class="w-4 h-4" :stroke-width="2" />
                </AppButton>
            </template>
        </AppActionSheet>

        <!-- The page's main verb, the rightmost, as the header rule wants. -->
        <AppButton
            v-for="action in primaryActions"
            :key="action.key"
            class="flex-1 sm:flex-none"
            variant="primary"
            :size="size"
            :href="action.href ?? null"
            :label="action.title"
            :disabled="action.disabled"
            :loading="action.loading"
            :icon-only-on-phone="iconOnlyOnPhone"
            v-on:click="runPrimary(action)"
        >
            <component :is="action.icon" v-if="action.icon" class="w-4 h-4" :stroke-width="2" />
        </AppButton>
    </div>
</template>
