/**
 * Drag-to-resize gesture for inline images in the markdown preview.
 *
 * The marked extension wraps every image in a `.note-image-wrap` with
 * a `.note-image-handle` corner grip carrying `data-md-handle="1"`.
 * This composable returns a single `onPointerDown(event)` handler the
 * SFC binds on its preview host; the handler ignores anything that
 * isn't the resize grip, and otherwise tracks the pointer until the
 * user releases it.
 *
 * Width is updated live on the live DOM image during the drag (so the
 * user gets immediate visual feedback). On release the composable
 * calls `onResize({ src, width })` so the parent can rewrite the
 * `![alt|N](src)` token in the markdown source - the next render of
 * `form.content` then picks up the new dimension from the alt suffix
 * and replaces the inline style with a baked-in width attribute. The
 * brief overlap is invisible: the inline style stays applied until the
 * v-html replace happens, and the new DOM image renders with the same
 * computed width.
 *
 * Pointer events (not mouse) so touch + pen drags work too. We also
 * listen for `pointercancel` because mobile browsers can interrupt a
 * drag (e.g. system gesture) and we want to release the listeners and
 * commit the last known width when that happens.
 *
 * @param {object} deps
 * @param {(payload: { src: string, width: number }) => void} deps.onResize
 *   Called on pointer-up with the image's src + the final pixel width.
 * @param {number} [deps.minWidth=40] Lower clamp (px) to stop the user
 *   from dragging an image down to 0.
 */
export function useNoteImageDragResize({ onResize, minWidth = 40 }) {
    function onPointerDown(event) {
        const handle = event.target?.closest?.("[data-md-handle]");
        if (!handle) return;
        event.preventDefault();

        const wrap = handle.closest(".note-image-wrap");
        const image = wrap?.querySelector("img");
        if (!image) return;

        handle.classList.add("is-active");
        const startX = event.clientX;
        const startWidth = image.getBoundingClientRect().width;
        let lastWidth = Math.round(startWidth);

        function onMove(moveEvent) {
            const delta = moveEvent.clientX - startX;
            const next = Math.max(minWidth, Math.round(startWidth + delta));
            if (next === lastWidth) return;
            lastWidth = next;
            image.style.width = `${next}px`;
            image.style.height = "auto";
        }

        function onUp() {
            window.removeEventListener("pointermove", onMove);
            window.removeEventListener("pointerup", onUp);
            window.removeEventListener("pointercancel", onUp);
            handle.classList.remove("is-active");
            const src = image.dataset.mdSrc ?? image.getAttribute("src");
            if (src) onResize({ src, width: lastWidth });
        }

        window.addEventListener("pointermove", onMove);
        window.addEventListener("pointerup", onUp);
        window.addEventListener("pointercancel", onUp);
    }

    return { onPointerDown };
}
