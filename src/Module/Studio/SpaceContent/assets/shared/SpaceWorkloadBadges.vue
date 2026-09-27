<script setup>
/**
 * What waits in one space, as pastilles: the non-zero states only, most
 * urgent first, the urgent ones tinted.
 *
 * One component for the dashboard and the list of spaces, so the same state
 * reads the same way on both - the counts already come from one service.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";

const props = defineProps({
    /** A row from `SpaceWorkload`: withClient, lateReview, changesRequested, missed. */
    workload: { type: Object, default: null },
});

const { t } = useI18n();

const BADGES = [
    { field: "missed", labelKey: "backend.studio.workload.badges.missed", urgent: true },
    { field: "lateReview", labelKey: "backend.studio.workload.badges.late_review", urgent: true },
    { field: "changesRequested", labelKey: "backend.studio.workload.badges.changes_requested", urgent: true },
    { field: "withClient", labelKey: "backend.studio.workload.badges.with_client", urgent: false },
];

const badges = computed(() =>
    BADGES.filter((badge) => (props.workload?.[badge.field] ?? 0) > 0).map((badge) => ({
        ...badge,
        count: props.workload[badge.field],
    })),
);
</script>

<template>
    <span v-if="badges.length" class="flex flex-wrap gap-1.5">
        <span
            v-for="badge in badges"
            :key="badge.field"
            class="rounded-full border px-2 py-0.5 text-xs tabular-nums whitespace-nowrap"
            :class="badge.urgent ? 'border-accent/40 text-accent' : 'border-line text-secondary'"
        >
            {{ t(badge.labelKey, { count: badge.count }, badge.count) }}
        </span>
    </span>
</template>
