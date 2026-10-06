<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import StudioSectionTabs from "../../../../assets/suite/components/StudioSectionTabs.vue";

/**
 * Contracts and templates, two tabs of the same menu entry.
 *
 * Templates long had their own entry, even though they already shared the
 * toggle, the route lock and the search with contracts: a template is written
 * to draw contracts from it.
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
