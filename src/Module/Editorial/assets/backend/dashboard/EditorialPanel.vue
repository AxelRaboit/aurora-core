<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, LayoutTemplate, Tags, Trash2 } from "lucide-vue-next";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";
import AppChart from "@/shared/components/display/AppChart.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { useChartPalette } from "@/shared/composables/chart/useChartPalette.js";

/**
 * Editorial's dashboard panel: how much content the site holds, and how it
 * is spread across the publication statuses.
 *
 * The shell hands over whatever EditorialStatsProvider returned, so every
 * field is defaulted - a dashboard is not the place to throw because a
 * figure was missing.
 */
const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();
const { formatMonthYear } = useDateFormat();
// Resolved, not `var(--chart-cat-1)`: a canvas cannot read a CSS property.
const { colours, withAlpha } = useChartPalette(1);

/**
 * The four figures, and the one that is not like the others.
 *
 * The trash tile is tinted rose, which is the colour every destructive action in
 * the backend already wears - so the tile reads as "retired" rather than as a
 * fourth count of something the site has.
 *
 * **Only when there is something in it.** An empty bin is not a destructive
 * state, and a tile that is always red says nothing by always saying it. The
 * icon and the label carry the meaning either way; the colour is what changes
 * when there is something to go and empty.
 */
const totals = computed(() => [
    { key: "posts", icon: FileText, value: props.stats.posts ?? 0 },
    { key: "post_types", icon: LayoutTemplate, value: props.stats.postTypes ?? 0 },
    { key: "taxonomies", icon: Tags, value: props.stats.taxonomies ?? 0 },
    {
        key: "trashed",
        icon: Trash2,
        value: props.stats.trashed ?? 0,
        danger: (props.stats.trashed ?? 0) > 0,
    },
]);

/**
 * The statuses in workflow order, which is the order the enum declares them in
 * and the order a reader follows: written, waiting, dated, out, retired.
 * `AppShareBar` assigns a colour per position, so keeping this stable keeps a
 * status the same colour from one visit to the next.
 */
/**
 * Publishing over the last twelve months: the only question on this panel that
 * is about change rather than about a current state, and the only one a bar or a
 * share cannot answer.
 *
 * Drawn with Chart.js rather than in DOM because it needs axes and ticks, which
 * is the line the house convention draws between the two - see
 * `convention_chart_palette`.
 */
const publishedByMonth = computed(() => {
    const months = Object.entries(props.stats.publishedByMonth ?? {});

    return {
        labels: months.map(([month]) => formatMonthYear(month)),
        datasets: [
            {
                label: t("backend.stats.editorial.published_per_month"),
                data: months.map(([, count]) => count),
                // One series, so no categorical palette: the first slot, and the
                // area under it at low opacity to give the line some weight
                // without a second colour.
                borderColor: colours.value[0],
                backgroundColor: withAlpha(0, 0.18),
                borderWidth: 2,
                pointRadius: 3,
                pointHoverRadius: 5,
                tension: 0.3,
                fill: true,
            },
        ],
    };
});

const hasActivity = computed(() =>
    Object.values(props.stats.publishedByMonth ?? {}).some((count) => count > 0),
);

const byCommentStatus = computed(() =>
    Object.entries(props.stats.commentsByStatus ?? {}).map(([status, count]) => ({
        key: status,
        label: t(`backend.comments.status.${status}`),
        value: count,
    })),
);

const byStatus = computed(() =>
    Object.entries(props.stats.byStatus ?? {}).map(([status, count]) => ({
        key: status,
        label: t(`backend.posts.status.${status}`),
        value: count,
    })),
);
</script>

<template>
    <div class="space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div
                v-for="total in totals"
                :key="total.key"
                class="border rounded-xl p-4 transition-colors"
                :class="total.danger
                    ? 'bg-rose-500/10 border-rose-500/30'
                    : 'bg-surface border-line'"
            >
                <div
                    class="flex items-center gap-2 text-xs uppercase tracking-wide"
                    :class="total.danger ? 'text-rose-400' : 'text-secondary'"
                >
                    <component :is="total.icon" class="w-4 h-4 shrink-0" :stroke-width="2" />
                    {{ t(`backend.stats.editorial.${total.key}`) }}
                </div>
                <p
                    class="text-2xl font-semibold mt-2"
                    :class="total.danger ? 'text-rose-400' : 'text-primary'"
                >
                    {{ total.value }}
                </p>
            </div>
        </div>

        <div v-if="byStatus.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.editorial.by_status") }}</h3>

            <AppShareBar :segments="byStatus" />
        </div>

        <div v-if="hasActivity" class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.editorial.published_per_month") }}</h3>

            <!-- A fixed height, because the canvas has no content to be sized by
                 and `maintainAspectRatio: false` leaves it to the parent. -->
            <div class="h-48">
                <AppChart type="line" :data="publishedByMonth" />
            </div>
        </div>

        <!-- Its own card rather than a second bar in the one above: two
             compositions of two different wholes under one heading would invite
             comparing their widths, which mean nothing to each other. -->
        <div v-if="byCommentStatus.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.editorial.comments_by_status") }}</h3>

            <AppShareBar :segments="byCommentStatus" />
        </div>
    </div>
</template>
