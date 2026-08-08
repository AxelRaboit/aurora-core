import { ref, onMounted, onUnmounted } from "vue";

/**
 * Tracks the active tab key, optionally persisted so it survives a reload.
 *
 * Two ways to persist, and they answer different questions.
 *
 * `storageKey` remembers a preference: which tab *this user* was last on,
 * everywhere that screen appears. Right for a settings page, where there is
 * one of them.
 *
 * `hash` puts the tab in the URL after the `#`. Right for a screen that
 * exists once per record — a post editor — where one remembered key would be
 * shared by every post, and two browser tabs on two posts would overwrite
 * each other. It also makes the tab shareable: a link can point at the header
 * of a specific page.
 *
 * The fragment never reaches the server, so nothing about the initial render
 * changes; the tab is chosen on mount. `replaceState` rather than assigning
 * `location.hash`, which would push a history entry per click — turning Back
 * into "previous tab" and trapping anyone trying to leave the page — and
 * would scroll to any element that happens to share the id.
 *
 * @param {string[]}    validKeys Allowed tab keys (used to discard stale values).
 * @param {object}      options
 * @param {string|null} [options.storageKey] When set, persists the active tab in localStorage.
 * @param {boolean}     [options.hash]       When true, mirrors the active tab in the URL fragment.
 * @param {string|null} [options.defaultKey] Falls back to the first valid key when omitted.
 */
export function useTabState(
    validKeys,
    { storageKey = null, hash = false, defaultKey = null } = {},
) {
    const fallback =
        defaultKey && validKeys.includes(defaultKey)
            ? defaultKey
            : validKeys[0];

    function fromHash() {
        if (!hash || "undefined" === typeof window) return null;
        const key = window.location.hash.replace(/^#/, "");

        return validKeys.includes(key) ? key : null;
    }

    function fromStorage() {
        if (!storageKey) return null;
        try {
            const saved = localStorage.getItem(storageKey);

            return saved && validKeys.includes(saved) ? saved : null;
        } catch (_) {
            /* ignored — private mode, full storage, etc. */
            return null;
        }
    }

    // The URL wins over the remembered preference: it is the more explicit of
    // the two, and it is what a shared link carries.
    const activeTab = ref(fromHash() ?? fromStorage() ?? fallback);

    function select(key) {
        if (!validKeys.includes(key)) return;

        activeTab.value = key;

        if (storageKey) {
            try {
                localStorage.setItem(storageKey, key);
            } catch (_) {
                /* ignored */
            }
        }

        if (hash && "undefined" !== typeof window) {
            window.history.replaceState(
                window.history.state,
                "",
                `${window.location.pathname}${window.location.search}#${key}`,
            );
        }
    }

    // Someone editing the fragment by hand, or arriving from a link while the
    // page is already open, should land on the tab they asked for.
    function syncFromHash() {
        const key = fromHash();
        if (key) activeTab.value = key;
    }

    if (hash && "undefined" !== typeof window) {
        onMounted(() => window.addEventListener("hashchange", syncFromHash));
        onUnmounted(() =>
            window.removeEventListener("hashchange", syncFromHash),
        );
    }

    function isActive(key) {
        return activeTab.value === key;
    }

    return { activeTab, select, isActive };
}
