<script setup>
/**
 * Says that a feature is still being finished.
 *
 * Put beside the switch that turns the feature on, not on the screens it then
 * changes: the moment to know is the moment of choosing. The tooltip says what
 * "beta" promises and what it does not, so the word is not left to each
 * reader's guess.
 */
import { useI18n } from "vue-i18n";

const { t } = useI18n();
</script>

<template>
    <span
        data-beta-badge
        class="ml-1.5 inline-flex shrink-0 items-center rounded-full border border-accent-500/40 bg-accent-500/10 px-1.5 py-px align-middle text-[10px] font-semibold uppercase tracking-wide text-accent-600 dark:text-accent-300"
        :title="t('shared.common.beta_hint')"
    >{{ t("shared.common.beta") }}</span>
</template>
