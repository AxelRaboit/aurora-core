<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, LayoutTemplate, Tags, Trash2 } from "lucide-vue-next";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";
import AppSectionCard from "@/shared/components/display/AppSectionCard.vue";
import AppStatTile from "@/shared/components/display/AppStatTile.vue";
import { POST_STATUS_CHART_SLOTS } from "@/shared/utils/format/statusStyles.js";
import { hasAnyShare } from "@/shared/utils/data/hasAnyShare.js";
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

const { t, locale } = useI18n();
const { formatMonthYear } = useDateFormat();
// Resolved, not `var(--chart-cat-1)`: a canvas cannot read a CSS property.
const { colours, withAlpha } = useChartPalette(1);

/**
 * The four figures, and the one that is not like the others.
 *
 * The trash tile is tinted rose, which is the colour every destructive action in
 * the suite already wears - so the tile reads as "retired" rather than as a
 * fourth count of something the site has.
 *
 * **Only when there is something in it.** An empty bin is not a destructive
 * state, and a tile that is always red says nothing by always saying it. The
 * icon and the label carry the meaning either way; the colour is what changes
 * when there is something to go and empty.
 */
const published = computed(() => props.stats.byStatus?.published ?? 0);

const totals = computed(() => [
    {
        key: "posts",
        icon: FileText,
        value: props.stats.posts ?? 0,
        // What a reader asks right after "how many": how many are out there.
        caption: t("suite.stats.editorial.posts_live", { count: published.value }),
    },
    { key: "post_types", icon: LayoutTemplate, value: props.stats.postTypes ?? 0 },
    { key: "taxonomies", icon: Tags, value: props.stats.taxonomies ?? 0 },
    // Not in red any more: a post in the trash is no fault, and red is kept
    // for what needs a gesture (UI audit of 07/10/2026).
    { key: "trashed", icon: Trash2, value: props.stats.trashed ?? 0 },
]);

/**
 * The statuses in workflow order, which is the order the enum declares them in
 * and the order a reader follows: written, waiting, dated, out, retired.
 * Each one names its own colour (`POST_STATUS_CHART_SLOTS`), the one its badge
 * wears in the posts list, so the bar and the list agree on what green means.
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
                label: t("suite.stats.editorial.published_per_month"),
                data: months.map(([, count]) => count),
                // One series, so no categorical palette: the first slot, and the
                // area under it at low opacity to give the line some weight
                // without a second colour.
                borderColor: colours.value[0],
                backgroundColor: withAlpha(0, 0.18),
                borderWidth: 2,
                // The last month is the one being lived: its point stands out,
                // the way a sparkline marks its end.
                pointRadius: (context) => (context.dataIndex === context.dataset.data.length - 1 ? 5 : 3),
                pointHoverRadius: 6,
                tension: 0.3,
                fill: true,
            },
        ],
    };
});

/**
 * The period the curve covers, from its first month to its last - "novembre
 * 2025 à octobre 2026" rather than a count of months, which made the reader
 * work out where the window started.
 */
const monthRange = computed(() => {
    const months = Object.keys(props.stats.publishedByMonth ?? {});
    if (!months.length) return "";

    // `formatMonthYear` capitalises for a label on its own. Mid-sentence,
    // French and Spanish write the month in lower case; English does not.
    const last = formatMonthYear(months[months.length - 1]);
    const lowerMonths = ["fr", "es"].includes(locale.value.slice(0, 2));

    return t("suite.stats.editorial.months_range", {
        from: formatMonthYear(months[0]),
        to: lowerMonths ? last.charAt(0).toLocaleLowerCase(locale.value) + last.slice(1) : last,
    });
});

const commentCount = computed(() =>
    Object.values(props.stats.commentsByStatus ?? {}).reduce((sum, count) => sum + count, 0),
);

const hasActivity = computed(() =>
    Object.values(props.stats.publishedByMonth ?? {}).some((count) => count > 0),
);

const byCommentStatus = computed(() =>
    Object.entries(props.stats.commentsByStatus ?? {}).map(([status, count]) => ({
        key: status,
        label: t(`suite.comments.status.${status}`),
        value: count,
    })),
);

const byStatus = computed(() =>
    Object.entries(props.stats.byStatus ?? {}).map(([status, count]) => ({
        key: status,
        label: t(`suite.posts.status.${status}`),
        value: count,
        slot: POST_STATUS_CHART_SLOTS[status],
    })),
);
</script>

<template>
    <div class="aurora-stack">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <AppStatTile
                v-for="total in totals"
                :key="total.key"
                :icon="total.icon"
                :label="t(`suite.stats.editorial.${total.key}`)"
                :value="total.value"
                :caption="total.caption ?? ''"
            />
        </div>

        <AppSectionCard v-if="hasAnyShare(byStatus)" :title="t('suite.stats.editorial.by_status')">
            <template #meta>
                {{ t("suite.stats.editorial.posts_total", { count: stats.posts ?? 0 }, stats.posts ?? 0) }}
            </template>

            <AppShareBar :segments="byStatus" />
        </AppSectionCard>

        <AppSectionCard v-if="hasActivity" :title="t('suite.stats.editorial.published_per_month')">
            <template #meta>
                {{ monthRange }}
            </template>

            <!-- A fixed height, because the canvas has no content to be sized by
                 and `maintainAspectRatio: false` leaves it to the parent. -->
            <div class="h-56">
                <AppChart type="line" :data="publishedByMonth" />
            </div>
        </AppSectionCard>

        <!-- Its own card rather than a second bar in the one above: two
             compositions of two different wholes under one heading would invite
             comparing their widths, which mean nothing to each other. -->
        <AppSectionCard v-if="hasAnyShare(byCommentStatus)" :title="t('suite.stats.editorial.comments_by_status')">
            <template #meta>
                {{ t("suite.stats.editorial.comments_total", { count: commentCount }, commentCount) }}
            </template>

            <AppShareBar :segments="byCommentStatus" />
        </AppSectionCard>
    </div>
</template>
