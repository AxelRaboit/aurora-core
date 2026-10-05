import { ref, watch } from "vue";

const STORAGE_KEY = "aurora.notes.markdown.viewMode";
const ALLOWED = ["edit", "split", "preview"];

/**
 * How the editor splits its screen: 'edit', 'split' or 'preview',
 * remembered in localStorage so the choice survives a reload, and falling
 * back to 'split' when storage is missing or invalid.
 *
 * It was called `useViewMode`, which is the name the library wanted for a
 * different thing entirely: mosaic, cards or list is a way of looking at the
 * notebook, this is a way of looking at one note being written.
 */
export function useEditorPaneMode() {
    const initial = readStored();
    const mode = ref(initial);

    watch(mode, (value) => {
        try {
            window.localStorage.setItem(STORAGE_KEY, value);
        } catch {
            // localStorage unavailable (private browsing, sandboxed) - ignore
        }
    });

    function readStored() {
        try {
            const stored = window.localStorage.getItem(STORAGE_KEY);
            return ALLOWED.includes(stored) ? stored : "split";
        } catch {
            return "split";
        }
    }

    return { mode };
}
