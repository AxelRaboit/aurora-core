/**
 * L'arrivée d'une zone de grille quand le lecteur la rejoint - `[data-reveal]`.
 *
 * **La page se lit sans ce fichier**, et c'est la contrainte qui décide de sa
 * forme. Rien n'est caché par la feuille de style : l'état de départ est posé
 * ici, zone par zone, par la classe `aurora-reveal-armed`. Script en erreur,
 * JavaScript coupé, navigateur sans IntersectionObserver : la classe n'est
 * jamais posée et le contenu s'affiche. Cacher d'abord pour remontrer ensuite
 * est la façon classique de perdre une page entière sur une erreur de
 * chargement.
 *
 * **Et une zone déjà à l'écran n'est jamais armée.** Le paquet pèse plusieurs
 * centaines de kilo-octets : sur une connexion lente la page est peinte bien
 * avant qu'il ne s'exécute, et armer à ce moment-là ferait disparaître puis
 * revenir ce que le lecteur regarde déjà. C'est aussi la bonne règle en soi -
 * animer l'entrée de ce qui est sous les yeux depuis le début n'annonce rien.
 *
 * Les mesures se font toutes avant les écritures. Lire une position puis
 * écrire une classe puis relire la suivante force le navigateur à recalculer
 * la mise en page à chaque tour, sur une page longue c'est trente fois.
 *
 * Un observateur pour toute la page, pas un par zone, et **une zone n'arrive
 * qu'une fois** : rejouer l'effet en remontant donne une page qui clignote
 * quand on cherche un paragraphe déjà lu, exactement le moment où il ne faut
 * pas bouger.
 *
 * Balisage : templates/Frontend/themes/default/editorial/post/_grid.html.twig
 */
const SELECTOR = "[data-reveal]";

/** Ce qui cache une zone en attendant son tour. Posé ici, jamais par le HTML. */
const ARMED = "aurora-reveal-armed";

/** La classe que porte une zone arrivée. */
const REVEALED = "aurora-revealed";

/**
 * Posé sur `<html>` tant qu'une zone attend son tour.
 *
 * Une zone qui vient de la droite est décalée hors de sa boîte, ce qui
 * allonge la page et lui donne une barre de défilement horizontale - mesuré
 * en production, vingt-quatre pixels, exactement le décalage. La feuille de
 * style coupe ce dépassement sous cet attribut, et il s'en va avec la
 * dernière zone : brider la page en permanence pour un mouvement qui dure
 * sept dixièmes de seconde serait payer trop cher.
 */
const RUNNING = "data-reveal-running";

/**
 * Assez de la zone pour que son arrivée se lise, et pas tant qu'une zone plus
 * haute que la fenêtre n'arrive jamais : d'où la marge basse, qui déclenche un
 * peu avant le bord plutôt que d'attendre une fraction qu'un grand bloc ne
 * franchira pas.
 */
const THRESHOLD = 0.08;
const MARGIN = "0px 0px -8% 0px";

/**
 * Le décalage entre deux arrivées d'un même groupe.
 *
 * Une galerie de vingt photos franchit le seuil d'un coup, et vingt zones qui
 * apparaissent ensemble ne se lisent pas comme vingt : elles se lisent comme
 * un bloc qui change d'opacité. Soixante-dix millisecondes suffisent à ce que
 * l'œil suive la série sans que la dernière se fasse attendre.
 *
 * **Le décalage se calcule au moment de l'arrivée, pas à l'écriture du
 * HTML.** Un rang gravé dans le balisage pénaliserait la vingtième photo même
 * quand on la rejoint seule, en bas de page, un quart d'heure plus tard :
 * elle attendrait 1,4 seconde pour rien. Ici, ce qui arrive ensemble se
 * décale, ce qui arrive seul n'attend pas.
 */
const STAGGER = 70;

/** Au-delà, on ne lit plus une cascade, on attend la fin. */
const STAGGER_MAX = 8;

/**
 * L'ordre dans lequel un lot d'arrivées se joue.
 *
 * Extrait de la fermeture de l'observateur pour être vérifiable : c'est la
 * seule logique de ce fichier qui décide de quelque chose, et un effet piloté
 * par le défilement ne se teste pas dans un navigateur sans le regarder.
 *
 * De haut en bas, puis de gauche à droite, parce que **le navigateur ne
 * promet rien sur l'ordre des entrées d'un même lot** et qu'une cascade qui
 * part du bas ou du milieu se remarque tout de suite.
 *
 * @param {Array<{isIntersecting: boolean, target: Element}>} entries
 *
 * @returns {Array<Element>} ce qui arrive, dans l'ordre où l'œil le prend
 */
export function cascadeOrder(entries) {
    return entries
        .filter((entry) => entry.isIntersecting)
        .map((entry) => ({
            target: entry.target,
            box: entry.target.getBoundingClientRect(),
        }))
        .sort((a, b) => a.box.top - b.box.top || a.box.left - b.box.left)
        .map(({ target }) => target);
}

/**
 * Le retard d'une arrivée selon son rang dans le lot, en millisecondes.
 *
 * Plafonné : au-delà de huit crans on ne lit plus une cascade, on attend la
 * fin. Une galerie de quarante photos qui franchissent le seuil ensemble
 * s'étalerait sinon sur près de trois secondes.
 */
export function cascadeDelay(rank) {
    return Math.min(Math.max(rank, 0), STAGGER_MAX) * STAGGER;
}

function reveal(element, remaining, rank = 0) {
    // Écrit en ligne plutôt qu'en CSS : le rang n'est connu qu'ici.
    if (rank > 0) {
        element.style.transitionDelay = `${cascadeDelay(rank)}ms`;
    }

    element.classList.add(REVEALED);

    // Rendu une fois posée : `will-change` laissé sur trente zones réserve de
    // la mémoire pour un mouvement qui ne se reproduira pas.
    element.addEventListener(
        "transitionend",
        () => {
            element.classList.remove(ARMED, REVEALED);
            element.style.transitionDelay = "";
            remaining.delete(element);

            if (0 === remaining.size) {
                document.documentElement.removeAttribute(RUNNING);
            }
        },
        { once: true },
    );
}

function arm() {
    const zones = Array.from(document.querySelectorAll(SELECTOR));

    if (0 === zones.length) {
        return;
    }

    // Le réglage système d'abord : on ne touche à rien.
    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    // Sans observateur, on laisse tout affiché plutôt que d'armer ce que rien
    // ne viendrait désarmer.
    if (!("IntersectionObserver" in window)) {
        return;
    }

    // Toutes les lectures, puis toutes les écritures.
    const below = zones.filter(
        (zone) => zone.getBoundingClientRect().top >= window.innerHeight,
    );

    if (0 === below.length) {
        return;
    }

    below.forEach((zone) => zone.classList.add(ARMED));
    document.documentElement.setAttribute(RUNNING, "");

    const remaining = new Set(below);

    const observer = new IntersectionObserver(
        (entries) => {
            cascadeOrder(entries).forEach((target, rank) => {
                reveal(target, remaining, rank);
                observer.unobserve(target);
            });
        },
        { threshold: THRESHOLD, rootMargin: MARGIN },
    );

    below.forEach((zone) => observer.observe(zone));

    // **Une page qui s'allonge après coup ne doit pas laisser du caché en
    // vue.** Les positions sont mesurées au chargement du document, avant
    // que les images ne se posent : une image sans dimensions occupe zéro
    // pixel, la page est donc plus courte qu'elle ne sera, et une zone jugée
    // « sous la fenêtre » peut se retrouver dedans une seconde plus tard
    // sans que rien ne la croise à nouveau - l'observateur ne se redéclenche
    // pas sur un élément qui n'a pas bougé par rapport à la fenêtre.
    //
    // La cause se corrige ailleurs, en écrivant `width` et `height` sur les
    // images. Ceci est le filet : à `load`, tout ce qui est armé et
    // désormais visible arrive, et le pire cas devient une zone qui apparaît
    // sans effet plutôt qu'une zone qui n'apparaît pas.
    window.addEventListener(
        "load",
        () => {
            remaining.forEach((zone) => {
                if (zone.getBoundingClientRect().top >= window.innerHeight) {
                    return;
                }

                reveal(zone, remaining);
                observer.unobserve(zone);
            });
        },
        { once: true },
    );
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
