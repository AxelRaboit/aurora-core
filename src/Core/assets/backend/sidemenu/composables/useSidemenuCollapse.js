import { onBeforeUnmount, onMounted, ref } from "vue";

/**
 * Announced whenever the menu folds or unfolds.
 *
 * The menu and the button that toggles it are two independent Vue apps — the
 * sidebar is mounted by the layout, the button by the page header — so neither
 * can see the other's refs. The class on `<html>` is the shared truth; this
 * event is how each one learns it changed without polling for it.
 *
 * Same shape as SIDEMENU_PREFS_EVENT, and for the same reason — see
 * pattern_cross_mount_state_sync.
 */
export const SIDEMENU_COLLAPSE_EVENT = "aurora:sidemenu-collapsed";

/**
 * Hiding and showing the sidemenu.
 *
 * Hidden means gone. It used to fold to a rail of icons, which cost a column of
 * the page for a row of glyphs nobody reads twice and forced every row in the
 * menu to have a second shape — labels hidden, icons centred, section headers
 * suppressed, and a special case so the logout row stayed reachable with its
 * own header off screen. Taking the menu away entirely deleted all of it, and
 * the page gets the width back.
 *
 * The choice is saved on the user rather than in the browser, beside the
 * sections they hid and the colours they picked — one object, one place. It
 * used to be the odd one out: hiding a section followed the account, folding
 * the whole menu followed the machine.
 *
 * The class is toggled first and the request is not awaited. A menu that waits
 * for a round-trip before moving feels broken, and the worst case of a failed
 * save is the menu coming back open on the next page — the same as before this
 * was persisted at all.
 *
 * The server also renders the class on first paint, so the menu no longer
 * starts expanded and snaps shut once a script has run.
 */
export function useSidemenuCollapse(collapsedPath = "") {
    /**
     * Whether the menu is hidden.
     *
     * Read from the class the server already renders on first paint, so it is
     * right before any script has run. Kept as a ref for the one thing that
     * still has to reason about it rather than be styled by it: the button in
     * the page header, which shows a different icon each way and is the only
     * way back once the menu is gone.
     */
    const collapsed = ref(
        document.documentElement.classList.contains("sidemenu-collapsed"),
    );
    function persist(collapsed) {
        if (!collapsedPath) return;

        void fetch(collapsedPath, {
            method: "POST",
            headers: {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            body: JSON.stringify({ collapsed }),
        }).catch(() => {
            /* ignored — see above: the menu keeps working either way */
        });
    }

    function apply(next) {
        document.documentElement.classList.toggle("sidemenu-collapsed", next);
        collapsed.value = next;
    }

    function announce(next) {
        window.dispatchEvent(
            new CustomEvent(SIDEMENU_COLLAPSE_EVENT, {
                detail: { collapsed: next },
            }),
        );
    }

    function collapse() {
        apply(true);
        announce(true);
        persist(true);
    }

    function expand() {
        apply(false);
        announce(false);
        persist(false);
    }

    /** One gesture for both directions, which is what a single control needs. */
    function toggle() {
        if (collapsed.value) {
            expand();

            return;
        }

        collapse();
    }

    // Follows what the other mount did, without re-persisting it: whoever
    // dispatched has already saved, and a second POST would race the first.
    function onAnnounced(event) {
        apply(Boolean(event.detail?.collapsed));
    }

    onMounted(() =>
        window.addEventListener(SIDEMENU_COLLAPSE_EVENT, onAnnounced),
    );
    onBeforeUnmount(() =>
        window.removeEventListener(SIDEMENU_COLLAPSE_EVENT, onAnnounced),
    );

    const mobileOpen = ref(false);

    function openMobile() {
        mobileOpen.value = true;
        document.body.style.overflow = "hidden";
    }

    function closeMobile() {
        mobileOpen.value = false;
        document.body.style.overflow = "";
    }

    return {
        collapsed,
        collapse,
        expand,
        toggle,
        mobileOpen,
        openMobile,
        closeMobile,
    };
}
