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

function reveal(element, remaining) {
    element.classList.add(REVEALED);

    // Rendu une fois posée : `will-change` laissé sur trente zones réserve de
    // la mémoire pour un mouvement qui ne se reproduira pas.
    element.addEventListener(
        "transitionend",
        () => {
            element.classList.remove(ARMED, REVEALED);
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
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                reveal(entry.target, remaining);
                observer.unobserve(entry.target);
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
