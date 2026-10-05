import { onBeforeUnmount, onMounted } from "vue";
import { SIDEMENU_COLLAPSE_EVENT } from "./useSidemenuCollapse.js";

/** The class that shows a folded menu over the page, without unfolding it. */
export const PEEK_CLASS = "sidemenu-peek";

/** How long the pointer rests on the edge before the menu comes. */
export const OPEN_DELAY_MS = 120;

/** How long it may wander off before the menu goes. */
export const CLOSE_DELAY_MS = 300;

/**
 * The folded menu, called back by the screen's left edge.
 *
 * Many applications do it: with the menu put away, the pointer pushed against
 * the left border brings it back over the page for as long as it is used, and
 * it slides away again once the pointer leaves. Nothing is saved - the menu is
 * still folded, the page keeps its width, and the header button stays the way
 * to unfold it for good.
 *
 * Both delays are there for the hand, not the machine. Opening waits a beat so
 * a cursor crossing the edge on its way to the browser's own tabs does not
 * throw a column across the page; closing waits a little longer so a pointer
 * that slips a few pixels outside while aiming at a row does not lose it.
 *
 * The edge itself is a strip the template renders and the stylesheet shows only
 * when the menu is folded and the screen is wide enough to have one.
 */
export function useSidemenuPeek() {
    let openTimer = null;
    let closeTimer = null;

    const root = () => document.documentElement;
    const peeking = () => root().classList.contains(PEEK_CLASS);

    function cancel() {
        clearTimeout(openTimer);
        clearTimeout(closeTimer);
        openTimer = null;
        closeTimer = null;
    }

    function show() {
        if (root().classList.contains("sidemenu-collapsed")) {
            root().classList.add(PEEK_CLASS);
        }
    }

    function hide() {
        cancel();
        root().classList.remove(PEEK_CLASS);
    }

    /** The pointer reached the edge. */
    function onEdgeEnter() {
        clearTimeout(closeTimer);
        openTimer = setTimeout(show, OPEN_DELAY_MS);
    }

    /** It left the edge before the menu came: it was only passing. */
    function onEdgeLeave() {
        clearTimeout(openTimer);
    }

    /** Back over the menu: stay. */
    function onMenuEnter() {
        clearTimeout(closeTimer);
    }

    /** Off the menu: go, unless it comes back in time. */
    function onMenuLeave() {
        if (!peeking()) return;
        closeTimer = setTimeout(hide, CLOSE_DELAY_MS);
    }

    function onKeydown(event) {
        if ("Escape" === event.key && peeking()) hide();
    }

    // Unfolding for good, or folding again, ends the peek: the class would
    // otherwise linger and show the menu the next time it is put away.
    onMounted(() => {
        window.addEventListener(SIDEMENU_COLLAPSE_EVENT, hide);
        window.addEventListener("keydown", onKeydown);
    });

    onBeforeUnmount(() => {
        window.removeEventListener(SIDEMENU_COLLAPSE_EVENT, hide);
        window.removeEventListener("keydown", onKeydown);
        hide();
    });

    return { onEdgeEnter, onEdgeLeave, onMenuEnter, onMenuLeave, hide };
}
