<script setup>
/**
 * A card with a title: the block every dashboard panel is made of.
 *
 * Each panel wrote its own - `aurora-card` plus an `h3`, with three paddings,
 * two weights for the title, and a link or a count squeezed beside it in
 * whatever way the panel's author thought of. The result was a dashboard whose
 * cards changed shape from one tab to the next (visual redesign of the suite,
 * 09/10/2026).
 *
 * The header holds the title on the left and, in the `meta` slot, what
 * qualifies the whole card on the right - a total, a period, a link to the
 * full screen. It wraps under the title on a phone rather than squeezing it.
 *
 * A `div`, not a `section`: screens keep `section` for their own groups, and
 * some of their tests count them.
 */
defineProps({
    title: { type: String, default: "" },
    /** The heading level, for a card that is not under a page-level `h2`. */
    headingTag: { type: String, default: "h3" },
});
</script>

<template>
    <div class="aurora-card min-w-0 p-4 sm:p-5">
        <div v-if="title || $slots.meta" class="mb-4 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <component :is="headingTag" class="min-w-0 text-sm font-semibold text-primary">{{ title }}</component>
            <div v-if="$slots.meta" class="flex items-baseline gap-3 text-xs text-secondary tabular-nums">
                <slot name="meta" />
            </div>
        </div>
        <slot />
    </div>
</template>
