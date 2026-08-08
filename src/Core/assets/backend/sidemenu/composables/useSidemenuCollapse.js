import { ref } from "vue";

/**
 * Collapsing and expanding the sidemenu.
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

    function collapse() {
        document.documentElement.classList.add("sidemenu-collapsed");
        persist(true);
    }

    function expand() {
        document.documentElement.classList.remove("sidemenu-collapsed");
        persist(false);
    }

    const mobileOpen = ref(false);

    function openMobile() {
        mobileOpen.value = true;
        document.body.style.overflow = "hidden";
    }

    function closeMobile() {
        mobileOpen.value = false;
        document.body.style.overflow = "";
    }

    return { collapse, expand, mobileOpen, openMobile, closeMobile };
}
