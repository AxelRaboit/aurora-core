<script setup>
import { computed } from "vue";
import { usePrivileges } from "@/shared/composables/usePrivileges.js";

/**
 * The tabs of a Studio menu entry that leads to several pages.
 *
 * Contracts and templates, spaces and calendar: each time, a single entry in
 * the menu, and pills at the top of the page to switch from one to the
 * other. The pills are the same segmented group as the deliverables'
 * shelves ("Perso", "Partagés"); each tab is a real page, with its address.
 *
 * A tab that requires a right the reader does not have disappears, and if
 * only one is left, so does the row: a single button offers no choice.
 *
 * The house segmented group, at its natural width and on one line, phone
 * included: stacked one tab per line on a 390 px phone, "Contrats" and
 * "Trames" read as two menu entries rather than two sides of one page. When
 * the labels do not fit, the group scrolls sideways instead of wrapping.
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
        class="flex w-fit max-w-full items-center gap-0.5 overflow-x-auto rounded-lg border border-line bg-surface-2/40 p-0.5 scrollbar-hide"
        role="tablist"
        :aria-label="label"
    >
        <button
            v-for="tab in visible"
            :key="tab.key"
            type="button"
            role="tab"
            :aria-selected="current === tab.key ? 'true' : 'false'"
            class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-md px-2.5 py-1 text-sm transition-colors"
            :class="current === tab.key ? 'bg-surface font-medium text-primary shadow-sm' : 'text-muted hover:text-primary'"
            v-on:click="open(tab)"
        >
            {{ tab.label }}
        </button>
    </div>
</template>
