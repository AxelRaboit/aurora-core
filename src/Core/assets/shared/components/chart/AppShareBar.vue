<script setup>
/**
 * How a total is split, as one bar.
 *
 * Replaces the row-of-progress-bars shape, which is the wrong form for this
 * question: five tracks each starting at zero ask the reader to compare five
 * lengths and then add them up, when what they want to see is one composition.
 * A pie is the other wrong answer - slices are harder to compare than lengths,
 * and five long category names have nowhere to sit on one.
 *
 * **The legend is not decoration.** Three of the light-mode hues sit under 3:1
 * against a white surface, which is only allowed when the value is also
 * readable as text. The legend is where that happens, so it is not optional and
 * the counts are not moved into the tooltip.
 *
 * Marks follow the house chart spec: a 2px gap in the surface colour between
 * touching segments rather than a border (a stroke adds ink that is not data),
 * rounded outer ends, and no label inside an interior segment - it has no free
 * end, so the legend and the tooltip carry it.
 *
 * Zero-value segments are dropped rather than rendered flat: a segment that is
 * there but invisible still costs a gap, which reads as a seam with nothing
 * behind it.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppTooltip from "@/shared/components/overlay/AppTooltip.vue";

const props = defineProps({
    /** `[{ key, label, value }]`, in the order they should be read. */
    segments: { type: Array, required: true },
    /**
     * Slots are assigned by position and never cycled: past the eighth the
     * palette has no distinguishable hue left, so a caller with more series
     * folds the tail itself rather than getting a colour that lies.
     *
     * A segment may also name its own `slot`, which wins. That is for the case
     * where the colour belongs to the thing rather than to its rank - a user
     * role is blue wherever it appears, and it must not change colour because a
     * role above it in the list has nobody in it.
     *
     * `slot: "neutral"` paints the segment in the interface's own grey, for
     * a state that is not a category but the absence of one - a draft nobody
     * has acted on yet. `"muted"` is the lighter grey, for a second such state
     * in the same bar - an archived post. `"ink"` is the text colour itself,
     * for the settled state of a series - a published post. The palette has no
     * grey on purpose, and borrowing a hue for "set aside" would give it a
     * meaning it has not.
     */
    firstSlot: { type: Number, default: 1 },
});

const { t } = useI18n();

function segmentColour(segment, index) {
    if ("neutral" === segment.slot) {
        return "var(--color-secondary)";
    }
    if ("muted" === segment.slot) {
        return "var(--color-muted)";
    }
    if ("ink" === segment.slot) {
        return "var(--color-primary)";
    }

    return `var(--chart-cat-${segment.slot ?? props.firstSlot + index})`;
}

const total = computed(() =>
    props.segments.reduce((sum, segment) => sum + (segment.value ?? 0), 0),
);

const drawn = computed(() =>
    props.segments
        .map((segment, index) => ({
            ...segment,
            value: segment.value ?? 0,
            colour: segmentColour(segment, index),
            percent:
                total.value > 0
                    ? Math.round(((segment.value ?? 0) / total.value) * 100)
                    : 0,
        }))
        .filter((segment) => segment.value > 0),
);
</script>

<template>
    <div v-if="total > 0" class="space-y-3">
        <!-- `gap-[3px]` is the surface gap. Widths come from flex-grow rather
             than percentages so the gaps are taken out of the free space and the
             row can never overflow. -->
        <div class="flex h-3 gap-[3px]">
            <AppTooltip
                v-for="(segment, index) in drawn"
                :key="segment.key"
                :title="segment.label"
                :description="t('shared.chart.share', { count: segment.value, total, percent: segment.percent })"
                placement="top"
            >
                <!--
                    Rounding comes from the index, not from `first:`/`last:`.
                    AppTooltip's root is `display: contents`, so each segment is
                    the only child of its own wrapper - both pseudo-classes match
                    every segment, and every joint came out rounded on both sides.

                    min-w keeps a one-of-many segment visible; the legend holds
                    the exact figure, so the rounding is never what is read.
                -->
                <div
                    class="h-full min-w-[3px] transition-opacity hover:opacity-80"
                    :class="[
                        index === 0 ? 'rounded-l-full' : '',
                        index === drawn.length - 1 ? 'rounded-r-full' : '',
                    ]"
                    :style="{ flex: `${segment.value} 1 0`, backgroundColor: segment.colour }"
                />
            </AppTooltip>
        </div>

        <!--
            A table of two columns, each entry on its own ruled row: the name
            on the left, the count and its share on the right (visual redesign
            of the suite, 10/10/2026, after the validated mockup).

            It replaces a wrapping line of compact entries, chosen once because
            a grid of half-card cells pushed the count to the far edge, where it
            read as belonging to nothing. The rule above each row is what ties
            them back together: the eye follows the line from "Brouillon" to
            its "1", the way it reads a ledger. One column on a phone.

            Read in the same order as the segments, so the reader maps colour to
            name by position as well as by hue.
        -->
        <ul class="grid grid-cols-1 gap-x-8 text-sm sm:grid-cols-2">
            <li
                v-for="segment in drawn"
                :key="segment.key"
                data-share-legend
                class="flex min-w-0 items-center gap-2.5 border-t border-line py-2.5"
            >
                <span
                    class="h-2.5 w-2.5 shrink-0 rounded-[3px]"
                    :style="{ backgroundColor: segment.colour }"
                />
                <span class="min-w-0 truncate text-secondary">{{ segment.label }}</span>
                <span class="ml-auto shrink-0 font-semibold tabular-nums text-primary">{{ segment.value }}</span>
                <span class="w-11 shrink-0 text-right text-xs tabular-nums text-muted">{{ segment.percent }}&nbsp;%</span>
            </li>
        </ul>
    </div>
</template>
