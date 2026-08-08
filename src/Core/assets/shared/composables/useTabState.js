import { ref, onMounted, onUnmounted } from "vue";

/**
 * Tracks which tab is active, optionally mirrored in the URL fragment.
 *
 * `hash: true` is what makes the choice survive a reload and makes it
 * shareable: "the header of that post", "the modules tab of the settings"
 * become links rather than places to be walked to. Use it whenever the tabs
 * divide a page someone might send.
 *
 * It replaces remembering the tab in `localStorage`, which this used to do.
 * A remembered key is one key for every instance of the screen — fine while
 * there was one settings page, wrong the moment tabs appeared on a per-record
 * editor, where two browser tabs on two records would overwrite each other.
 * The URL belongs to the page being looked at, which is what the tab does too.
 *
 * Left off for tabs inside a modal or a widget, where the page is not what is
 * being divided and a fragment would be noise.
 *
 * The fragment never reaches the server, so nothing about the initial render
 * changes; the tab is chosen on mount. `replaceState` rather than assigning
 * `location.hash`, which would push a history entry per click — turning Back
 * into "previous tab" and trapping anyone trying to leave the page — and
 * would scroll to any element that happens to share the id.
 *
 * @param {string[]}    validKeys Allowed tab keys (used to discard stale values).
 * @param {object}      options
 * @param {boolean}     [options.hash]       When true, mirrors the active tab in the URL fragment.
 * @param {string|null} [options.defaultKey] Falls back to the first valid key when omitted.
 */
export function useTabState(
    validKeys,
    { hash = false, defaultKey = null } = {},
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

    const activeTab = ref(fromHash() ?? fallback);

    function select(key) {
        if (!validKeys.includes(key)) return;

        activeTab.value = key;

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
