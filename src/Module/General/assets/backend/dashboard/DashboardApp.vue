<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import AppGuide from "@/shared/components/feedback/AppGuide.vue";
import DashboardOverview from "@general/backend/dashboard/DashboardOverview.vue";

const props = defineProps({
    stats: { type: Object, default: () => ({}) },
    enabledModules: { type: Object, default: () => ({}) },
});

const { t } = useI18n();

// The guide explains the module tabs: with no module to show, the empty state
// says everything there is to say.
const hasModule = computed(() => Object.values(props.enabledModules).some(Boolean));
</script>

<template>
    <div class="space-y-5">
        <!-- Le mode d'emploi de l'écran, à côté de ce qu'il explique ;
             replié ou déplié, le choix vaut pour tous les encarts. -->
        <AppGuide v-if="hasModule" :title="t('backend.stats.guide.title')" storage-key="dashboard">
            <ol class="m-0 flex list-decimal flex-col gap-1.5 pl-5">
                <li v-for="step in 4" :key="step">{{ t(`backend.stats.guide.step_${step}`) }}</li>
            </ol>
        </AppGuide>
        <DashboardOverview :stats="stats" :enabled-modules="enabledModules" />
    </div>
</template>
