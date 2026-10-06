<script setup>
/**
 * When the client's answer is due, on a card still waiting for it.
 *
 * The deadline was set in the form and then shown nowhere: the header said
 * « 2 late » without saying which. A card now says its own - quietly while
 * there is time, as a warning once it has passed. Nothing is drawn for a card
 * that has been answered or has no deadline.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Hourglass } from "lucide-vue-next";

const props = defineProps({
    item: { type: Object, required: true },
});

const { t, d: formatDate } = useI18n();

const due = computed(() =>
    "pending" === props.item.approval && props.item.reviewBy ? formatDate(new Date(props.item.reviewBy), "short") : null,
);
</script>

<template>
    <span
        v-if="due && item.lateForReview"
        class="flex shrink-0 items-center gap-1 rounded-full bg-warning-soft px-1.5 py-0.5 text-xs text-warning"
        :title="t('suite.studio.space_content.review.late_hint')"
    >
        <Hourglass class="h-3 w-3 shrink-0" :stroke-width="2" />
        {{ t("suite.studio.space_content.review.late_since", { date: due }) }}
    </span>
    <span v-else-if="due" class="flex shrink-0 items-center gap-1 text-xs text-muted">
        <Hourglass class="h-3 w-3 shrink-0" :stroke-width="2" />
        {{ t("suite.studio.space_content.review.due", { date: due }) }}
    </span>
</template>
