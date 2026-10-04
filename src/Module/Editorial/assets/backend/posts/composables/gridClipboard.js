import { ref } from "vue";

/**
 * Zones copied from one grid to paste in another: a publication's section
 * into a deliverable, a deliverable's page into the next one.
 *
 * Kept in the browser's storage, so a copy made in one tab is there in the
 * next - which is the whole point, the two documents are rarely open in the
 * same editor. Storage can refuse (a private window, a full quota): the copy
 * then simply does not happen, and nothing breaks.
 */
const KEY = "aurora.grid.clipboard";

function read() {
    try {
        const stored = JSON.parse(
            globalThis.localStorage?.getItem(KEY) ?? "null",
        );

        return Array.isArray(stored?.zones) && stored.zones.length
            ? stored
            : null;
    } catch {
        return null;
    }
}

const clipboard = ref(read());

if ("undefined" !== typeof window) {
    window.addEventListener("storage", (event) => {
        if (KEY === event.key) clipboard.value = read();
    });
}

/** What is waiting to be pasted, or null. */
export function useGridClipboard() {
    /** Keeps a copy; true when the browser accepted it. */
    function copy(payload) {
        try {
            globalThis.localStorage?.setItem(KEY, JSON.stringify(payload));
            clipboard.value = read();

            return null !== clipboard.value;
        } catch {
            return false;
        }
    }

    function clear() {
        try {
            globalThis.localStorage?.removeItem(KEY);
        } catch {
            // Nothing to undo: an unreadable storage held nothing either.
        }
        clipboard.value = null;
    }

    return { clipboard, copy, clear };
}
