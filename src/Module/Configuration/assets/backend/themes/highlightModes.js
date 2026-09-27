/**
 * The colour modes of hovers and of the topbar's active marker.
 *
 * Mirrors ThemeContext::HIGHLIGHTS, and held to it by
 * ThemeHighlightModesMirrorTest: a mode added on one side only would be
 * offered and then ignored, or accepted and never offered.
 */
export const HIGHLIGHT_MODES = ["accent", "neutral", "custom"];

/** The modes as select options, labelled in the current language. */
export function highlightModeOptions(t) {
    return HIGHLIGHT_MODES.map((value) => ({
        value,
        label: t(`backend.themes.highlight_${value}`),
    }));
}
