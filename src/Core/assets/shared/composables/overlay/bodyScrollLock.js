/**
 * One body scroll lock, counted, shared by every overlay on the page.
 *
 * It used to be a bare `document.body.style.overflow = "hidden"` on open and
 * `= ""` on close, inside each modal. That is a last-writer-wins rule, and it
 * breaks in both directions as soon as two overlays exist at once:
 *
 *   - the shallower one closes and *unlocks* the page while a modal is still
 *     up, so the reader scrolls the document behind it;
 *   - a modal unmounts while open - the branch it lived in stopped rendering -
 *     and nothing ever runs the release, so the page stays frozen with no
 *     overlay in sight and no way to get it back.
 *
 * The second one is the expensive one, because there is nothing on screen to
 * blame. It stopped being hypothetical when the side menu started mounting
 * modals of its own: two Vue applications, two sets of overlays, one `body`.
 *
 * Each caller gets a release function that is safe to call twice, so an unmount
 * and a close cannot double-decrement between them.
 */
let holders = 0;
let previousOverflow = null;

export function lockBodyScroll() {
    if (0 === holders) {
        previousOverflow = document.body.style.overflow;
        document.body.style.overflow = "hidden";
    }
    holders += 1;

    let released = false;

    return function release() {
        if (released) return;
        released = true;
        holders -= 1;

        if (0 === holders) {
            document.body.style.overflow = previousOverflow ?? "";
            previousOverflow = null;
        }
    };
}

/** Test seam: forget every holder and restore the page. */
export function resetBodyScrollLock() {
    holders = 0;
    document.body.style.overflow = previousOverflow ?? "";
    previousOverflow = null;
}
