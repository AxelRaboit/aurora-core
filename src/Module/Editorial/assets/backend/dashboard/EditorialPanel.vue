<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, LayoutTemplate, Tags, Trash2 } from "lucide-vue-next";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";

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

const totals = computed(() => [
    { key: "posts", icon: FileText, value: props.stats.posts ?? 0 },
    { key: "post_types", icon: LayoutTemplate, value: props.stats.postTypes ?? 0 },
    { key: "taxonomies", icon: Tags, value: props.stats.taxonomies ?? 0 },
    { key: "trashed", icon: Trash2, value: props.stats.trashed ?? 0 },
]);

/**
 * The statuses in workflow order, which is the order the enum declares them in
 * and the order a reader follows: written, waiting, dated, out, retired.
 * `AppShareBar` assigns a colour per position, so keeping this stable keeps a
 * status the same colour from one visit to the next.
 */
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
                class="bg-surface border border-line rounded-xl p-4"
            >
                <div class="flex items-center gap-2 text-secondary text-xs uppercase tracking-wide">
                    <component :is="total.icon" class="w-4 h-4 shrink-0" :stroke-width="2" />
                    {{ t(`backend.stats.editorial.${total.key}`) }}
                </div>
                <p class="text-2xl font-semibold text-primary mt-2">{{ total.value }}</p>
            </div>
        </div>

        <div v-if="byStatus.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.editorial.by_status") }}</h3>

            <AppShareBar :segments="byStatus" />
        </div>
    </div>
</template>
