<script setup>
/**
 * Le gabarit d'une intégration de la configuration : Google Drive, Instagram,
 * Pexels, la lettre d'information, les avis Google, GitHub, le captcha, Craft.
 *
 * **L'état d'abord, la connexion ensuite, le mode d'emploi à côté.** Chaque
 * onglet ouvrait sur « Ce que fait cette intégration » puis « Comment
 * l'ouvrir », et le premier champ arrivait un écran plus bas ; rien ne disait
 * en tête si l'intégration marchait. Ici, une phrase et un badge d'état, puis
 * la carte des réglages, et le mode d'emploi dans un encart qui reste ouvert
 * tant que rien n'est branché et se replie ensuite. Sur un grand écran les
 * deux se tiennent côte à côte ; sur téléphone, le mode d'emploi passe devant
 * tant qu'il sert, derrière une fois l'intégration active.
 *
 * Ce composant ne sait rien de chaque intégration : l'onglet lui donne son
 * état et remplit ses emplacements.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";

const props = defineProps({
    /** Ce que fait l'intégration, en une phrase. */
    summary: { type: String, default: "" },
    /** `active` (branchée et allumée), `off` (prête, éteinte) ou `todo` (à configurer). */
    status: { type: String, default: "todo" },
    loading: { type: Boolean, default: false },
    /** Le titre de la carte des réglages ; « Connexion » par défaut. */
    settingsTitle: { type: String, default: "" },
});

const { t } = useI18n();

const STATUS_COLORS = { active: "emerald", off: "gray", todo: "amber" };

const statusColor = computed(() => STATUS_COLORS[props.status] ?? "gray");
const statusLabel = computed(() => t(`backend.settings.integration.status_${STATUS_COLORS[props.status] ? props.status : "todo"}`));

/** Le mode d'emploi sert tant que rien ne marche ; ensuite il se replie. */
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
                        {{ settingsTitle || t("backend.settings.integration.settings_title") }}
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
                :title="t('backend.settings.integration.guide_title')"
                :open="guideOpen"
                :class="guideOpen ? 'order-first lg:order-none' : ''"
            >
                <slot name="guide" />
            </AppGuide>
        </div>
    </div>
</template>
