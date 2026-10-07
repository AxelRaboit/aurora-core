<script setup>
/**
 * The layout of a configuration integration: Google Drive, Instagram, Pexels,
 * the newsletter, Google reviews, GitHub, the captcha, Craft.
 *
 * **Status first, connection next, how-to guide alongside.** Each tab opened
 * on "What this integration does" then "How to open it", and the first field
 * came one screen lower; nothing at the top said whether the integration
 * worked. Here, a sentence and a status badge, then the settings card, and
 * the how-to guide in a panel that stays open as long as nothing is connected
 * and folds afterwards. On a large screen the two sit side by side; on a
 * phone, the how-to guide comes first while it is useful, and after once the
 * integration is active.
 *
 * This component knows nothing about each integration: the tab gives it its
 * status and fills its slots.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";

const props = defineProps({
    /** What the integration does, in one sentence. */
    summary: { type: String, default: "" },
    /** `active` (connected and on), `off` (ready, off) or `todo` (to configure). */
    status: { type: String, default: "todo" },
    loading: { type: Boolean, default: false },
    /** The title of the settings card; "Connexion" by default. */
    settingsTitle: { type: String, default: "" },
});

const { t } = useI18n();

const STATUS_COLORS = { active: "emerald", off: "gray", todo: "amber" };

const statusColor = computed(() => STATUS_COLORS[props.status] ?? "gray");
const statusLabel = computed(() => t(`suite.settings.integration.status_${STATUS_COLORS[props.status] ? props.status : "todo"}`));

/** The how-to guide is useful as long as nothing works; then it folds. */
const guideOpen = computed(() => "active" !== props.status);
</script>

<template>
    <div class="relative flex flex-col gap-5">
        <AppLoader :active="loading" />

        <header class="flex flex-wrap items-start justify-between gap-3">
            <p v-if="summary" class="m-0 max-w-2xl text-sm text-secondary">{{ summary }}</p>
            <AppBadge :color="statusColor">{{ statusLabel }}</AppBadge>
        </header>

        <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
            <div class="flex min-w-0 flex-col gap-4">
                <article class="aurora-card flex flex-col gap-4 p-3 sm:p-4">
                    <h3 class="m-0 text-sm font-medium text-primary">
                        {{ settingsTitle || t("suite.settings.integration.settings_title") }}
                    </h3>
                    <slot />
                    <div v-if="$slots.actions" class="flex flex-col gap-2 border-t border-line/60 pt-3 sm:flex-row sm:justify-end">
                        <slot name="actions" />
                    </div>
                </article>
                <slot name="after" />
            </div>

            <AppGuide
                v-if="$slots.guide"
                :title="t('suite.settings.integration.guide_title')"
                :open="guideOpen"
                :class="guideOpen ? 'order-first lg:order-none' : ''"
            >
                <slot name="guide" />
            </AppGuide>
        </div>
    </div>
</template>
