/**
 * The expanded folders, remembered in the browser.
 *
 * A reading habit, not a state of the notebook: it only concerns the person
 * sitting there, and the panel and the reader share it, so that the same
 * folders are found open from one space to the other. An unavailable or
 * damaged storage gives a folded tree, a minor inconvenience and not an
 * outage.
 */
export const EXPANDED_KEY = "aurora.notes.panel.expanded";

/** @returns {Set<number>} */
export function readExpanded() {
    try {
        const ids = JSON.parse(
            window.localStorage.getItem(EXPANDED_KEY) ?? "[]",
        );

        return new Set(Array.isArray(ids) ? ids.map(Number) : []);
    } catch {
        return new Set();
    }
}

/** @param {Set<number>} ids */
export function storeExpanded(ids) {
    try {
        window.localStorage.setItem(EXPANDED_KEY, JSON.stringify([...ids]));
    } catch {
        // Same: the session goes on without memory.
    }
}
