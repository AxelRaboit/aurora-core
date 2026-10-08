import { caretPositionIn } from "./caretPosition.js";

/**
 * Position a floating menu (slash palette, wiki-link autocomplete)
 * next to a character inside a `<textarea>`.
 *
 * The caret's own position comes from {@see caretPositionIn}, which three
 * callers now need; what is left here is the part that is about a *menu* -
 * clamping it to the screen and picking the side with more room.
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
 * @param {object} [options]
 * @param {number} [options.menuWidth=224]  - matches `min-w-56` (14rem)
 * @param {number} [options.menuHeight=256] - matches `max-h-64` (16rem)
 * @param {number} [options.gap=8]          - space between caret line and menu
 * @param {number} [options.margin=8]       - viewport edge breathing room
 * @returns {{ top: number, left: number, maxHeight: number }}
 */
export function positionFloatingMenu(textarea, startIndex, options = {}) {
    const { menuWidth = 224, menuHeight = 256, gap = 8, margin = 8 } = options;

    const caret = caretPositionIn(textarea, startIndex);
    const caretTop = caret.top;
    const caretBottom = caretTop + caret.lineHeight;

    // ── Horizontal ────────────────────────────────────────────────────────
    let left = caret.left;
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
