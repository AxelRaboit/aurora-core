import { ref } from "vue";

/**
 * Manages a map of expanded/collapsed states persisted in localStorage.
 * Default state is expanded (true) - only explicit collapses are stored.
 */
export function usePersistedExpanded(storageKey) {
    const expanded = ref(load());

    function load() {
        try {
            const raw = localStorage.getItem(storageKey);
            return raw ? JSON.parse(raw) : {};
        } catch {
            return {};
        }
    }

    function isExpanded(id) {
        return expanded.value[id] !== false;
    }

    function toggle(id) {
        expanded.value = { ...expanded.value, [id]: !isExpanded(id) };
        localStorage.setItem(storageKey, JSON.stringify(expanded.value));
    }

    function getRaw(id) {
        return expanded.value[id];
    }

    return { isExpanded, toggle, getRaw };
}
