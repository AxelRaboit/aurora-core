/**
 * Passer un livrable de la présentation à la page, et retour, au même endroit.
 *
 * Les deux vues partagent l'adresse des sections, `#diapo-N` : la diapositive
 * N en présentation, la première zone de la section N en page. Les liens
 * `[data-view-switch]` mènent à `?view=page` ou `?view=slides` ; sans script,
 * ils ouvrent l'autre vue en haut du document, et c'est encore juste.
 *
 * - De la présentation, l'adresse porte déjà la diapositive affichée
 *   (`slides.js` la tient à jour) : on la garde.
 * - De la page, la section est celle dont le début est passé sous le haut de
 *   l'écran, la dernière qui l'a été.
 * - En arrivant sur la page avec `#diapo-N`, si l'auteur a mis sa propre ancre
 *   sur la zone (l'identifiant n'est alors pas `diapo-N`), on la retrouve par
 *   son numéro.
 */

const SECTION = /^#diapo-(\d+)$/;

/** La section où se trouve le lecteur en page, ou null en haut du document. */
export function currentSection(root = document, offset = 120) {
    let current = null;

    for (const element of root.querySelectorAll("[data-section]")) {
        const rect = element.getBoundingClientRect();
        // Une zone masquée à cette largeur (« cacher sur téléphone ») n'a pas
        // de taille et se dit en haut de l'écran : elle ne dit pas où l'on est.
        if (0 === rect.width && 0 === rect.height) continue;
        if (rect.top <= offset) {
            current = Number(element.dataset.section);
        }
    }

    return current;
}

/** L'adresse de l'autre vue, au même endroit. */
export function switchHref(
    target,
    { hash = window.location.hash, root = document } = {},
) {
    if ("page" === target) {
        return `?view=page${SECTION.test(hash) ? hash : ""}`;
    }

    const section = currentSection(root);

    return `?view=slides${null !== section ? `#diapo-${section}` : ""}`;
}

/** Arrivé en page sur `#diapo-N` dont la zone porte l'ancre de l'auteur. */
export function revealSection(hash = window.location.hash, root = document) {
    const match = SECTION.exec(hash ?? "");
    if (!match || root.getElementById?.(`diapo-${match[1]}`)) return;

    root.querySelector(`[data-section="${match[1]}"]`)?.scrollIntoView({
        block: "start",
    });
}

function init() {
    document.querySelectorAll("[data-view-switch]").forEach((link) => {
        link.addEventListener("click", () => {
            link.setAttribute("href", switchHref(link.dataset.viewSwitch));
        });
    });

    if (document.querySelector("[data-section]")) revealSection();
}

if ("undefined" !== typeof document) {
    if ("loading" === document.readyState) {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
}
