import { ref, onMounted, onBeforeUnmount } from "vue";

/**
 * Custom event name fired by the profile settings page after a save.
 * Kept here (and exported) so both ends agree on the contract without
 * a magic string lookup.
 */
export const SIDEMENU_PREFS_EVENT = "aurora:sidemenu-prefs-updated";

/**
 * PHP's `json_encode([])` returns `[]` (Array) for an empty associative
 * array - Vue prop typing accepts it as Object since arrays are objects
 * in JS, but our consumers expect a plain `Record<string, string>`.
 * This normalises both the seed and event payloads to a fresh object.
 */
function normaliseColorMap(value) {
    if (!value || Array.isArray(value) || typeof value !== "object") {
        return {};
    }

    return { ...value };
}

/**
 * Reactive mirror of `app.user.navSectionColors`. The Twig layout seeds
 * the value via prop on first render; from then on, the profile
 * settings page broadcasts `SIDEMENU_PREFS_EVENT` after a save so the
 * sidemenu - which lives in a separate Vue mount point - can update
 * its colour overrides without a full page reload.
 *
 * @param {unknown} initial seed value coming from the Twig-provided prop
 * @returns {{ liveSectionColors: import('vue').Ref<Record<string, string>> }}
 */
export function useSidemenuLiveColors(initial) {
    const liveSectionColors = ref(normaliseColorMap(initial));

    function handlePrefsUpdated(event) {
        liveSectionColors.value = normaliseColorMap(
            event.detail?.navSectionColors,
        );
    }

    onMounted(() =>
        window.addEventListener(SIDEMENU_PREFS_EVENT, handlePrefsUpdated),
    );
    onBeforeUnmount(() =>
        window.removeEventListener(SIDEMENU_PREFS_EVENT, handlePrefsUpdated),
    );

    return { liveSectionColors };
}
