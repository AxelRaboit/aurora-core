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
 * See {@see AppActionSheet} for the shape of an action.
 */
import { useI18n } from "vue-i18n";
import { MoreHorizontal } from "lucide-vue-next";
import AppButton from "./AppButton.vue";
import AppActionSheet from "./AppActionSheet.vue";

defineProps({
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
});

// AppActionSheet has two roots (the trigger and its modal): a `class` set on
// this component landed nowhere, neither `w-full sm:w-auto` nor the others
// (14 calls). It now goes on the button, which is what you see.
defineOptions({ inheritAttrs: false });

const { t } = useI18n();
</script>

<template>
    <AppActionSheet :actions="actions" :label="label">
        <template #trigger="{ open }">
            <AppButton
                v-bind="$attrs"
                :variant="variant"
                :size="size"
                :loading="busy"
                :label="t('shared.actions.plain_title')"
                :icon-only-on-phone="iconOnlyOnPhone"
                :title="t('shared.actions.plain_title')"
                v-on:click="open"
            >
                <MoreHorizontal v-if="!busy" class="w-4 h-4" :stroke-width="2" />
            </AppButton>
        </template>
    </AppActionSheet>
</template>
