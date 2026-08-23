import { ref } from "vue";

/**
 * Track which sections are collapsed, keyed by an arbitrary id (typically
 * the section/parameter `key`). Default state is "all expanded" - only
 * collapsed keys are stored, so a new section appearing later in the
 * payload is open by default without any pre-population.
 *
 * Returned API:
 *   - isExpanded(key): true when the section is open
 *   - toggle(key):    flip the open/closed state of one section
 *   - expandAll():    forget every collapsed key
 *   - collapseAll(keys): mark every passed key as collapsed
 *
 * Encapsulated in a composable so the SFC stays declarative and the same
 * collapse machinery can be reused by other tabs that need section folding.
 */
export function useCollapsibleSections() {
    const collapsedKeys = ref(new Set());

    function isExpanded(key) {
        return !collapsedKeys.value.has(key);
    }

    function toggle(key) {
        const next = new Set(collapsedKeys.value);
        if (next.has(key)) next.delete(key);
        else next.add(key);
        collapsedKeys.value = next;
    }

    function expandAll() {
        collapsedKeys.value = new Set();
    }

    function collapseAll(keys) {
        collapsedKeys.value = new Set(keys);
    }

    return { isExpanded, toggle, expandAll, collapseAll };
}
