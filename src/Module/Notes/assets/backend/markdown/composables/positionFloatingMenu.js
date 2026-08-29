/**
 * Position a floating menu (slash palette, wiki-link autocomplete)
 * next to a character inside a `<textarea>`.
 *
 * Uses the classic mirror-div trick: build an off-screen <div> with
 * the same font/padding/width as the textarea, fill it with the text
 * up to `startIndex`, and read the on-screen rect of a marker placed
 * at that position. The returned coordinates are expressed relative
 * to the textarea's positioned ancestor (consumers wrap the textarea
 * in a `.relative` div and feed these into `position: absolute`).
 *
 * The result is clamped to the viewport so the menu never overflows
 * the visible area on narrow screens:
 *   - Horizontally, if the menu would clip the right edge, we slide
 *     it left until it fits (with an 8px margin).
 *   - Vertically, if the menu would clip the bottom edge, we render
 *     it above the caret instead of below (with the same 8px margin).
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {number} startIndex - caret offset to anchor the menu to
 * @param {object} [opts]
 * @param {number} [opts.menuWidth=224]  - matches `min-w-56` (14rem)
 * @param {number} [opts.menuHeight=256] - matches `max-h-64` (16rem)
 * @param {number} [opts.gap=24]         - vertical offset below caret
 * @param {number} [opts.margin=8]       - viewport edge breathing room
 * @returns {{ top: number, left: number }}
 */
export function positionFloatingMenu(textarea, startIndex, opts = {}) {
    const { menuWidth = 224, menuHeight = 256, gap = 24, margin = 8 } = opts;

    const text = textarea.value.substring(0, startIndex);
    const mirror = document.createElement("div");
    const style = window.getComputedStyle(textarea);

    mirror.style.position = "absolute";
    mirror.style.visibility = "hidden";
    mirror.style.whiteSpace = "pre-wrap";
    mirror.style.overflowWrap = "break-word";
    mirror.style.width = style.width;
    mirror.style.font = style.font;
    mirror.style.letterSpacing = style.letterSpacing;
    mirror.style.padding = style.padding;
    mirror.style.lineHeight = style.lineHeight;
    mirror.style.boxSizing = style.boxSizing;
    mirror.style.border = style.border;

    mirror.textContent = text;
    const marker = document.createElement("span");
    marker.textContent = "|";
    mirror.appendChild(marker);

    document.body.appendChild(mirror);

    const textareaRect = textarea.getBoundingClientRect();
    const markerRect = marker.getBoundingClientRect();
    const mirrorRect = mirror.getBoundingClientRect();

    // Position relative to the textarea's wrapper (consumers always
    // use a `.relative` div whose top/left match the textarea's).
    let top = markerRect.top - mirrorRect.top - textarea.scrollTop + gap;
    let left = markerRect.left - mirrorRect.left;

    document.body.removeChild(mirror);

    // ── Horizontal clamp ──────────────────────────────────────────
    // Convert local x → viewport x, clamp, then convert back.
    const viewportLeft = textareaRect.left + left;
    const maxViewportLeft = window.innerWidth - menuWidth - margin;
    if (viewportLeft > maxViewportLeft) {
        left -= viewportLeft - maxViewportLeft;
    }
    if (left < 0) left = 0;

    // ── Vertical clamp ────────────────────────────────────────────
    // If the menu placed gap pixels below the caret would clip the
    // bottom of the viewport, flip it above the caret line instead.
    const caretViewportTop = markerRect.top - textarea.scrollTop;
    const menuViewportBottom = caretViewportTop + gap + menuHeight;
    if (menuViewportBottom > window.innerHeight - margin) {
        // Place the menu so its bottom sits ~gap pixels above the
        // caret. `lineHeight` is parsed to know how tall a row is.
        const lineHeight = parseFloat(style.lineHeight) || 20;
        top = top - gap - menuHeight - lineHeight + gap;
    }

    return { top, left };
}
