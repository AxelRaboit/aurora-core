<script setup>
import { computed } from "vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";
import AppTab from "@/shared/components/nav/AppTab.vue";

/**
 * The tabs of a Studio menu entry that leads to several pages.
 *
 * Contracts and templates, spaces and calendar: each time, a single entry in
 * the menu, and pills at the top of the page to switch from one to the
 * other. The pills reuse the deliverables' ones ("Mes livrables",
 * "Partagés"); each tab is a real page, with its address.
 *
 * A tab that requires a right the reader does not have disappears, and if
 * only one is left, so does the row: a single button offers no choice.
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
