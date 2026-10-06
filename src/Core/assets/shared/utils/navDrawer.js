/**
 * The phone navigation drawer - `<details data-nav-drawer>`.
 *
 * The `<details>` already provides all the heavy lifting, and `data-dropdown`
 * adds Escape to it (see `detailsDropdown.js`). What is missing comes from
 * the backdrop: a modal panel lays a curtain over the page, and that curtain
 * intercepts precisely the "outside" click the other module was waiting for.
 * It is therefore closed here.
 *
 * Two other cases close the drawer. A link to an anchor of the current page
 * reloads nothing: without this the panel would stay open in front of the
 * place you wanted to go. And the screen widening beyond `md` - a rotation
 * to landscape - brings back the expanded bar, where the drawer no longer
 * has a button: it would reappear open on returning to portrait.
 *
 * Without this file the drawer stays usable: the button that opened it
 * closes it, with a cross, and Escape too.
 *
 * Markup: templates/Frontend/themes/default/partials/nav_drawer.html.twig
 */

const DRAWER = "details[data-nav-drawer]";

// The same width as the template's `md:`. Tailwind does not expose it to the
// script, so it is written here - and the two must move together.
const WIDE = "(min-width: 768px)";

function close(drawer) {
    drawer.open = false;
    // Hand control back to the button rather than leaving the focus on an
    // element that has just disappeared.
    drawer.querySelector("summary")?.focus();
}

function onClick(event) {
    const drawer = event.target.closest?.(DRAWER);
    if (!drawer?.open) return;

    if (
        event.target.closest("[data-nav-drawer-veil]") ||
        event.target.closest('a[href^="#"]')
    ) {
        close(drawer);
    }
}

function onWiden(event) {
    if (!event.matches) return;

    document.querySelectorAll(`${DRAWER}[open]`).forEach((drawer) => {
        drawer.open = false;
    });
}

document.addEventListener("click", onClick);
window.matchMedia(WIDE).addEventListener("change", onWiden);
