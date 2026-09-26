<script setup>
import { useI18n } from "vue-i18n";

/**
 * What a row says about a document beyond its name: whether anything draws
 * it, whether it is kept on purpose, and where it sits in its family.
 *
 * One component for the cards and the table, which used to write the unused
 * badge twice and could already disagree.
 *
 * "Inutilisé" and "À conserver" show together on purpose: the first is a
 * fact the server measures, the second a decision somebody took about it,
 * and seeing both is what tells a reader not to tidy it away.
 */
defineProps({
    doc: { type: Object, required: true },
});

const { t } = useI18n();
</script>

<template>
    <span
        v-if="0 === doc.usageCount"
        :title="t('backend.ged.documents.usage_unused_hint')"
        class="text-xs px-1.5 py-0.5 rounded border border-amber-500/40 text-amber-600 dark:text-amber-400"
    >{{ t("backend.ged.documents.usage_unused_badge") }}</span>
    <span
        v-if="doc.kept"
        :title="t('backend.ged.documents.kept_hint')"
        class="text-xs px-1.5 py-0.5 rounded border border-sky-500/40 text-sky-600 dark:text-sky-400"
    >{{ t("backend.ged.documents.kept_badge") }}</span>
    <span
        v-if="doc.alternateCount > 0"
        class="text-xs px-1.5 py-0.5 rounded border border-violet-500/40 text-violet-600 dark:text-violet-400"
    >{{ t("backend.ged.documents.alternates_badge", { count: doc.alternateCount }, doc.alternateCount) }}</span>
    <span
        v-if="doc.originalId"
        :title="t('backend.ged.documents.alternate_of', { title: doc.originalTitle ?? '' })"
        class="text-xs px-1.5 py-0.5 rounded border border-violet-500/40 text-violet-600 dark:text-violet-400"
    >{{ doc.alternateLabel ? t("backend.ged.documents.alternate_badge_labelled", { label: doc.alternateLabel }) : t("backend.ged.documents.alternate_badge") }}</span>
</template>
