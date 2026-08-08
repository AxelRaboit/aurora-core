<script setup>
import { computed, markRaw } from "vue";
import { useI18n } from "vue-i18n";
import { PanelLeft } from "lucide-vue-next";
import AppTab from "@/shared/components/nav/AppTab.vue";
import SidemenuTab from "@general/backend/profile/preferences/tabs/SidemenuTab.vue";
import { useTabState } from "@/shared/composables/useTabState.js";

const { t } = useI18n();

const props = defineProps({
    navPreferences: { type: Array, default: () => [] },
    sectionAliases: { type: Object, default: () => ({}) },
    itemAliases: { type: Object, default: () => ({}) },
    hiddenNavSections: { type: Array, default: () => [] },
    hiddenNavItems: { type: Array, default: () => [] },
    navSectionColors: { type: Object, default: () => ({}) },
    savePath: { type: String, required: true },
    resetPath: { type: String, required: true },
});

const TABS = [
    {
        key: "sidemenu",
        labelKey: "backend.profile.preferences.tabs.sidemenu",
        icon: PanelLeft,
        component: markRaw(SidemenuTab),
        getProps: () => ({
            navPreferences: props.navPreferences,
            sectionAliases: props.sectionAliases,
            itemAliases: props.itemAliases,
            hiddenNavSections: props.hiddenNavSections,
            hiddenNavItems: props.hiddenNavItems,
            navSectionColors: props.navSectionColors,
            savePath: props.savePath,
            resetPath: props.resetPath,
        }),
    },
];

const { activeTab, select: selectTab, isActive: isActiveTab } = useTabState(
    TABS.map((tab) => tab.key),
    { hash: true },
);

const current = computed(() => TABS.find((tab) => tab.key === activeTab.value) ?? TABS[0]);
</script>

<template>
    <div class="flex flex-col md:flex-row gap-6">
        <nav class="hidden md:flex flex-col w-44 shrink-0 gap-0.5">
            <AppTab
                v-for="tab in TABS"
                :key="tab.key"
                :active="isActiveTab(tab.key)"
                v-on:click="selectTab(tab.key)"
            >
                <component :is="tab.icon" class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                {{ t(tab.labelKey) }}
            </AppTab>
        </nav>

        <div class="flex md:hidden gap-1 flex-wrap mb-4 w-full">
            <AppTab
                v-for="tab in TABS"
                :key="tab.key"
                :active="isActiveTab(tab.key)"
                size="sm"
                v-on:click="selectTab(tab.key)"
            >
                <component :is="tab.icon" class="w-3.5 h-3.5 shrink-0" :stroke-width="2" />
                {{ t(tab.labelKey) }}
            </AppTab>
        </div>

        <div class="flex-1 min-w-0">
            <div class="bg-surface border border-line rounded-xl p-4 sm:p-6">
                <component :is="current.component" v-bind="current.getProps()" />
            </div>
        </div>
    </div>
</template>
