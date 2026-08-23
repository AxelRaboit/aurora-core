<script setup>
/**
 * The GED's dashboard panel: what the library holds, and what kind of files.
 *
 * Same shape as Editorial's, deliberately: two rows, four figures then one
 * composition. A dashboard whose panels each invent a layout makes the reader
 * relearn it on every tab.
 *
 * Every field is defaulted - a dashboard is not the place to throw because a
 * figure was missing.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { FileText, FolderTree, Tag, Tags } from "lucide-vue-next";
import AppShareBar from "@/shared/components/chart/AppShareBar.vue";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

const totals = computed(() => [
    { key: "documents", icon: FileText, value: props.stats.documents ?? 0 },
    { key: "categories", icon: Tags, value: props.stats.categories ?? 0 },
    { key: "tags", icon: Tag, value: props.stats.tags ?? 0 },
    { key: "folders", icon: FolderTree, value: props.stats.folders ?? 0 },
]);

/**
 * The four file buckets, in the order the library's own filter lists them, so a
 * type keeps its colour between the dashboard and the documents list.
 */
const byType = computed(() =>
    Object.entries(props.stats.byType ?? {}).map(([group, count]) => ({
        key: group,
        label: t(`backend.ged.documents.type_${group}`),
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
                    {{ t(`backend.stats.ged.${total.key}`) }}
                </div>
                <p class="text-2xl font-semibold text-primary mt-2">{{ total.value }}</p>
            </div>
        </div>

        <div v-if="byType.length" class="bg-surface border border-line rounded-xl p-5 space-y-4">
            <h3 class="text-sm font-semibold text-primary">{{ t("backend.stats.ged.by_type") }}</h3>

            <AppShareBar :segments="byType" />
        </div>
    </div>
</template>
