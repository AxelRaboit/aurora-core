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
     */
    firstSlot: { type: Number, default: 1 },
});

const { t } = useI18n();

const total = computed(() =>
    props.segments.reduce((sum, segment) => sum + (segment.value ?? 0), 0),
);

const drawn = computed(() =>
    props.segments
        .map((segment, index) => ({
            ...segment,
            value: segment.value ?? 0,
            colour: `var(--chart-cat-${props.firstSlot + index})`,
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
        <!-- `gap-0.5` is the 2px surface gap. Widths come from flex-grow rather
             than percentages so the gaps are taken out of the free space and the
             row can never overflow. -->
        <div class="flex gap-0.5 h-3">
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
            A wrapping row of compact entries, not a grid of stretched ones. On a
            grid the cells are half the card wide, so the count drifted to the
            far right and read as belonging to nothing: "Brouillon" at one edge
            and "1" at the other. Each entry keeps its own parts together and the
            row wraps when it runs out of width.

            Read in the same order as the segments, so the reader maps colour to
            name by position as well as by hue.
        -->
        <ul class="flex flex-wrap gap-x-5 gap-y-1.5 text-sm">
            <li
                v-for="segment in drawn"
                :key="segment.key"
                class="flex items-baseline gap-1.5 min-w-0"
            >
                <span
                    class="w-2 h-2 rounded-full shrink-0 self-center"
                    :style="{ backgroundColor: segment.colour }"
                />
                <span class="text-secondary truncate">{{ segment.label }}</span>
                <span class="text-primary font-medium tabular-nums shrink-0">{{ segment.value }}</span>
                <span class="text-muted text-xs tabular-nums shrink-0">{{ segment.percent }}&nbsp;%</span>
            </li>
        </ul>
    </div>
</template>
