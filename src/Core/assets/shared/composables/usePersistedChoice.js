import { ref, watch } from "vue";

/**
 * One choice a reader makes about how a screen looks, remembered for them.
 *
 * For preferences and nothing else: which of three views of the same data
 * somebody prefers, how a list is sorted. Never for what the screen is showing
 * - that belongs in the URL, so it can be sent to a colleague and survives
 * being opened twice.
 *
 * `localStorage`, so it is this person on this machine. That is the right
 * scope for a taste: two people sharing a screen do not share an opinion about
 * kanban, and a preference that followed an account across devices would
 * change the layout on a phone because of a choice made on a desktop.
 *
 * Every access is guarded. A private window, cleared site data or a browser
 * that blocks storage all make these throw rather than return null, and a
 * screen that cannot remember a preference must still draw.
 *
 * @param {string} key        storage key, namespaced by the caller
 * @param {string} fallback   what to use when nothing is stored
 * @param {string[]} allowed  the values that mean something; anything else
 *                            falls back, so a renamed view does not leave
 *                            readers on a blank screen
 */
export function usePersistedChoice(key, fallback, allowed = []) {
    const choice = ref(read());

    function read() {
        try {
            const stored = localStorage.getItem(key);

            if (null === stored) return fallback;
            if (allowed.length && !allowed.includes(stored)) return fallback;

            return stored;
        } catch {
            return fallback;
        }
    }

    watch(choice, (value) => {
        try {
            localStorage.setItem(key, value);
        } catch {
            // Nothing to do and nothing to say: the screen works, it just
            // opens on the default next time.
        }
    });

    return { choice };
}
