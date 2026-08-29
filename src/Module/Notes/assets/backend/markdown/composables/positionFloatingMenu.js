/**
 * Position a floating menu (slash palette, wiki-link autocomplete)
 * next to a character inside a `<textarea>`.
 *
 * Uses the classic mirror-div trick: build an off-screen <div> with the same
 * font/padding/width as the textarea, fill it with the text up to
 * `startIndex`, and read the on-screen rect of a marker placed at that
 * position.
 *
 * The returned coordinates are **viewport** coordinates, for `position: fixed`.
 * They used to be relative to the textarea's wrapper, for `position: absolute`,
 * which put the menu inside the editor's `overflow-auto` pane and let that pane
 * cut it in half whenever it opened near an edge. A caret-anchored menu has to
 * be able to leave its container; nothing else can be clipped by it.
 *
 * Placement picks the side with more room rather than defaulting to below:
 *   - below the caret when the menu fits there,
 *   - above when it does not and there is more room above,
 *   - and in either case `maxHeight` comes back shrunk to the space actually
 *     available, so a menu that fits nowhere scrolls instead of being cropped.
 * Horizontally it slides left to stay on screen.
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {number} startIndex - caret offset to anchor the menu to
 * @param {object} [opts]
 * @param {number} [opts.menuWidth=224]  - matches `min-w-56` (14rem)
 * @param {number} [opts.menuHeight=256] - matches `max-h-64` (16rem)
 * @param {number} [opts.gap=8]          - space between caret line and menu
 * @param {number} [opts.margin=8]       - viewport edge breathing room
 * @returns {{ top: number, left: number, maxHeight: number }}
 */
export function positionFloatingMenu(textarea, startIndex, opts = {}) {
    const { menuWidth = 224, menuHeight = 256, gap = 8, margin = 8 } = opts;

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
    const lineHeight = parseFloat(style.lineHeight) || 20;

    // Offset of the caret inside the textarea, then into the viewport.
    const caretOffsetTop = markerRect.top - mirrorRect.top - textarea.scrollTop;
    const caretOffsetLeft = markerRect.left - mirrorRect.left;

    document.body.removeChild(mirror);

    const caretTop = textareaRect.top + caretOffsetTop;
    const caretBottom = caretTop + lineHeight;

    // ── Horizontal ────────────────────────────────────────────────────────
    let left = textareaRect.left + caretOffsetLeft;
    const maxLeft = window.innerWidth - menuWidth - margin;
    if (left > maxLeft) left = maxLeft;
    if (left < margin) left = margin;

    // ── Vertical ──────────────────────────────────────────────────────────
    const roomBelow = window.innerHeight - caretBottom - gap - margin;
    const roomAbove = caretTop - gap - margin;

    let top;
    let maxHeight;

    if (menuHeight <= roomBelow || roomBelow >= roomAbove) {
        // Below: the default, and the fallback when neither side fits but
        // below is the roomier of the two.
        top = caretBottom + gap;
        maxHeight = Math.max(0, Math.min(menuHeight, roomBelow));
    } else {
        maxHeight = Math.max(0, Math.min(menuHeight, roomAbove));
        top = caretTop - gap - maxHeight;
    }

    if (top < margin) top = margin;

    return { top, left, maxHeight };
}
