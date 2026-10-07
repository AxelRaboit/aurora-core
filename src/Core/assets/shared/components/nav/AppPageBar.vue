<script setup>
/**
 * The header bar of a screen: the way back on the left, the commands on the
 * right.
 *
 * **A single layout, everywhere** (Axel's decision of 02/10/2026). Before it,
 * each screen had its own: the way back on the left and the commands pushed
 * to the right by a `justify-between` (publication), by a spacer (gallery),
 * by an `ml-auto` (notes), or stuck right after the way back (contract
 * template, adapted text), and on a computer their place depended on the
 * length of the title. The title does not go into the bar: it comes below,
 * on its own line, where it can be long without pushing anything.
 *
 * The commands there are real buttons, of the same size (md, 38 px), and
 * turn icon only below `sm` (`AppButton` `icon-only-on-phone`,
 * `AppPageActions` `icon-only-on-phone`): the bar then fits on one line at
 * 375 px. The way back remains navigation, a bare chevron ({@see AppBackLink}).
 *
 * `start` receives what goes with the way back on the left (rarely); the
 * default slot, the commands, in reading order: the "Actions" sheet, then
 * the main verb, furthest to the right.
 */
import AppBackLink from "./AppBackLink.vue";

defineProps({
    /** Where the way back leads: the parent the breadcrumb names. */
    backHref: { type: String, default: null },
    /** The parent's name, never "Back": visible from `sm`, always read by screen readers. */
    backLabel: { type: String, default: null },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
        <AppBackLink v-if="backLabel && backHref" :href="backHref" :label="backLabel" />
        <slot name="start" />
        <div class="ml-auto flex shrink-0 flex-wrap items-center justify-end gap-2">
            <slot />
        </div>
    </div>
</template>
