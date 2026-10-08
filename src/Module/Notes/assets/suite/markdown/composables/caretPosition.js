/**
 * Where a character offset sits on screen, inside a `<textarea>`.
 *
 * **The mirror-div trick.** A textarea gives no access to the geometry of its
 * own text, so an off-screen `<div>` is built with the same font, width,
 * padding and wrapping, filled with the text up to the offset, and a marker is
 * measured inside it. That marker's rectangle is where the caret would be.
 *
 * Extracted because a third caller needed it: the slash palette and the
 * wiki-link autocomplete both anchor a menu to the caret, and a remote
 * person's cursor has to be drawn at one. Three similar sites is the rule for
 * pulling a helper out - and it is also the only way the two uses cannot
 * drift, which matters here because the measurement is the fiddly part and
 * each copy would be fiddly differently.
 *
 * Viewport coordinates, for `position: fixed`. The caller decides what to do
 * with them: {@see positionFloatingMenu} clamps them to the screen and picks a
 * side for a menu; a remote cursor is drawn where it actually is.
 *
 * @param {HTMLTextAreaElement} textarea
 * @param {number} index character offset in `textarea.value`
 * @returns {{top: number, left: number, lineHeight: number}}
 */
export function caretPositionIn(textarea, index) {
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

    mirror.textContent = textarea.value.substring(0, index);

    // A visible glyph rather than an empty span: an empty inline element has
    // no height of its own in some engines, and the rectangle came back flat.
    const marker = document.createElement("span");
    marker.textContent = "|";
    mirror.appendChild(marker);

    document.body.appendChild(mirror);

    const textareaRect = textarea.getBoundingClientRect();
    const markerRect = marker.getBoundingClientRect();
    const mirrorRect = mirror.getBoundingClientRect();
    const lineHeight = parseFloat(style.lineHeight) || 20;

    // Inside the textarea first - minus its own scroll - then into the
    // viewport. Scrolling is why this cannot be measured once and cached.
    const offsetTop = markerRect.top - mirrorRect.top - textarea.scrollTop;
    const offsetLeft = markerRect.left - mirrorRect.left;

    document.body.removeChild(mirror);

    return {
        top: textareaRect.top + offsetTop,
        left: textareaRect.left + offsetLeft,
        lineHeight,
    };
}
