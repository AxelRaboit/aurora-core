import { ref, watch } from "vue";

const STORAGE_KEY = "aurora.notes.markdown.viewMode";
const ALLOWED = ["edit", "split", "preview"];

/**
 * Reactive view-mode state ('edit' | 'split' | 'preview') persisted in
 * localStorage so the user's choice survives reloads. Falls back to
 * 'split' when storage is missing or invalid.
 */
export function useViewMode() {
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
