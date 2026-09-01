<script setup>
import { computed, markRaw } from "vue";
import SidemenuTab from "@general/backend/profile/preferences/tabs/SidemenuTab.vue";

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

/**
 * The one tab there is.
 *
 * `useTabState` kept the choice in the URL fragment, which is what the settings
 * page did before its tabs became addresses. There is no choice to keep here -
 * one entry - so the fragment was recording an answer that could not vary.
 */
const current = computed(() => TABS[0]);
</script>

<template>
    <!-- No tab strip: `TABS` has one entry, so the column was a navigation
         between one destination and itself. The other pages that had one -
         the settings, the dev administration - moved theirs into the side
         menu; there is nothing here to move, only a control to drop. Put the
         strip back the day a second tab exists, or contribute it to the module
         view like the settings tabs. -->
    <div class="bg-surface border border-line rounded-xl p-4 sm:p-6">
        <component :is="current.component" v-bind="current.getProps()" />
    </div>
</template>
