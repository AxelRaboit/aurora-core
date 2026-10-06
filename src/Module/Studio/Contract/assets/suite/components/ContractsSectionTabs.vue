<script setup>
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppTab from "@/shared/components/nav/AppTab.vue";

/**
 * Contrats et trames, deux onglets d'une même entrée du menu.
 *
 * Les trames ont longtemps eu leur propre entrée, alors qu'elles partageaient
 * déjà avec les contrats l'interrupteur, le verrou des routes et la recherche :
 * on écrit une trame pour en tirer des contrats. Les pastilles reprennent
 * celles des livrables (« Mes livrables », « Partagés »), et chaque onglet est
 * une vraie page, avec son adresse.
 *
 * Un seul onglet visible ne s'affiche pas : une rangée d'un bouton n'offre
 * aucun choix.
 */
const props = defineProps({
    current: { type: String, required: true }, // contracts | templates
    contractsPath: { type: String, required: true },
    templatesPath: { type: String, required: true },
});

const { t } = useI18n();
const { can } = usePrivileges();

const tabs = computed(() =>
    [
        { key: "contracts", label: t("suite.nav.studio_contracts"), path: props.contractsPath, privilege: "studio.contracts.view" },
        { key: "templates", label: t("suite.studio.contract_templates.tab"), path: props.templatesPath, privilege: "studio.contract_templates.view" },
    ].filter((tab) => can(tab.privilege)),
);

function open(tab) {
    if (tab.key !== props.current) window.location.href = tab.path;
}
</script>

<template>
    <div
        v-if="tabs.length > 1"
        class="flex w-full flex-col p-1 bg-surface-2 border border-line rounded-lg gap-1 sm:inline-flex sm:w-auto sm:flex-row sm:self-start"
        role="tablist"
        :aria-label="t('suite.studio.contract_templates.tabs_label')"
    >
        <AppTab
            v-for="tab in tabs"
            :key="tab.key"
            role="tab"
            :aria-selected="current === tab.key ? 'true' : 'false'"
            size="sm"
            class="justify-between sm:flex-none sm:justify-start"
            :active="current === tab.key"
            active-class="bg-surface text-primary shadow-sm"
            inactive-class="text-secondary hover:text-primary"
            v-on:click="open(tab)"
        >
            {{ tab.label }}
        </AppTab>
    </div>
</template>
