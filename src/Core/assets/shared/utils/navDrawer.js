/**
 * Le tiroir de navigation du téléphone - `<details data-nav-drawer>`.
 *
 * Le `<details>` donne déjà tout le gros oeuvre, et `data-dropdown` y ajoute
 * Échap (voir `detailsDropdown.js`). Ce qui manque tient au voile : un panneau
 * modal pose un rideau par-dessus la page, et ce rideau intercepte justement le
 * clic « au dehors » que l'autre module attendait. Il est donc refermé ici.
 *
 * Deux autres cas ferment le tiroir. Un lien vers une ancre de la page courante
 * ne recharge rien : sans cela le panneau resterait ouvert devant l'endroit où
 * on voulait aller. Et l'écran qui s'élargit au-delà de `md` - une rotation en
 * paysage - rend la barre dépliée, où le tiroir n'a plus de bouton : il
 * réapparaîtrait ouvert au retour en portrait.
 *
 * Sans ce fichier le tiroir reste utilisable : le bouton qui l'a ouvert le
 * referme, croix à l'appui, et Échap aussi.
 *
 * Markup: templates/Frontend/themes/default/partials/nav_drawer.html.twig
 */

const DRAWER = "details[data-nav-drawer]";

// La même largeur que le `md:` du gabarit. Tailwind ne l'expose pas au script,
// donc elle est écrite ici - et les deux doivent bouger ensemble.
const WIDE = "(min-width: 768px)";

function close(drawer) {
    drawer.open = false;
    // Rendre la main au bouton plutôt que de laisser le focus sur un élément
    // qui vient de disparaître.
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
