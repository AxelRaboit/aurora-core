<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import StudioSectionTabs from "../../../../assets/suite/components/StudioSectionTabs.vue";

/**
 * Contrats et trames, deux onglets d'une même entrée du menu.
 *
 * Les trames ont longtemps eu leur propre entrée, alors qu'elles partageaient
 * déjà avec les contrats l'interrupteur, le verrou des routes et la recherche :
 * on écrit une trame pour en tirer des contrats.
 */
const props = defineProps({
    current: { type: String, required: true }, // contracts | templates
    contractsPath: { type: String, required: true },
    templatesPath: { type: String, required: true },
});

const { t } = useI18n();

const tabs = computed(() => [
    { key: "contracts", label: t("suite.nav.studio_contracts"), path: props.contractsPath, privilege: "studio.contracts.view" },
    { key: "templates", label: t("suite.studio.contract_templates.tab"), path: props.templatesPath, privilege: "studio.contract_templates.view" },
]);
</script>

<template>
    <StudioSectionTabs
        :current="current"
        :tabs="tabs"
        :label="t('suite.studio.contract_templates.tabs_label')"
    />
</template>
