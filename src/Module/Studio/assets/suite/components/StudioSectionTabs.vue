<script setup>
import { computed } from "vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppTab from "@/shared/components/nav/AppTab.vue";

/**
 * Les onglets d'une entrée du menu de Studio qui mène à plusieurs pages.
 *
 * Contrats et trames, espaces et calendrier : chaque fois, une seule entrée
 * dans le menu, et des pastilles en tête de page pour passer de l'une à
 * l'autre. Les pastilles reprennent celles des livrables (« Mes livrables »,
 * « Partagés ») ; chaque onglet est une vraie page, avec son adresse.
 *
 * Un onglet qui demande un droit que le lecteur n'a pas disparaît, et s'il
 * n'en reste qu'un, la rangée aussi : un seul bouton n'offre aucun choix.
 */
const props = defineProps({
    current: { type: String, required: true },
    /** @type {Array<{ key: string, label: string, path: string, privilege?: string }>} */
    tabs: { type: Array, required: true },
    label: { type: String, required: true },
});

const { can } = usePrivileges();

const visible = computed(() =>
    props.tabs.filter((tab) => tab.path && (!tab.privilege || can(tab.privilege))),
);

function open(tab) {
    if (tab.key !== props.current) window.location.href = tab.path;
}
</script>

<template>
    <div
        v-if="visible.length > 1"
        class="flex w-full flex-col p-1 bg-surface-2 border border-line rounded-lg gap-1 sm:inline-flex sm:w-auto sm:flex-row sm:self-start"
        role="tablist"
        :aria-label="label"
    >
        <AppTab
            v-for="tab in visible"
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
