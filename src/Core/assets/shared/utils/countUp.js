/**
 * Un chiffre qui monte jusqu'à sa valeur quand le lecteur le rejoint.
 *
 * **La valeur finale est déjà dans le HTML**, et c'est la contrainte qui
 * décide de tout le reste : ce fichier la lit, compte jusqu'à elle, et la
 * repose. Script en erreur, JavaScript coupé, moteur de recherche, lecteur
 * d'écran : le chiffre juste est là, écrit par le serveur. Partir de zéro
 * dans le balisage aurait mis un faux chiffre sur la page pour tout ce qui
 * ne joue pas l'animation.
 *
 * **Ce qui n'est pas un nombre n'est pas touché.** La valeur d'un chiffre est
 * du texte libre : « 24 », mais aussi « 3 langues », « +40 % », « 2 à 3 ».
 * Seul un nombre en tête, éventuellement suivi d'une unité, se compte ; tout
 * le reste s'affiche tel quel. On préfère ne rien animer à animer de travers.
 *
 * Le format vient de la page : séparateur de milliers, virgule décimale,
 * nombre de décimales sont relus de la valeur écrite, pas devinés d'une
 * locale. C'est le serveur qui a écrit « 1 250 », il n'y a pas de raison de
 * réinventer comment.
 *
 * Balisage : templates/Frontend/themes/default/editorial/post/_grid_items.html.twig
 */
const SELECTOR = "[data-count-up]";

/** Assez pour qu'on voie monter, assez peu pour ne pas faire attendre. */
const DURATION = 900;

const THRESHOLD = 0.4;

/**
 * Ce qu'on sait lire : un nombre en tête, le reste conservé.
 *
 * Les espaces acceptés comme séparateurs de milliers incluent l'espace
 * insécable étroit (U+202F), celui que PHP écrit en français, et l'insécable
 * ordinaire (U+00A0) - sans eux « 1 250 » se lirait « 1 ».
 *
 * **Un séparateur ne compte que suivi de trois chiffres.** Sans cette
 * exigence l'expression avalait aussi l'espace qui sépare un nombre de son
 * unité : « 3 langues » donnait le nombre 3 et le reste « langues », qui se
 * réaffichait collé, « 3langues ».
 */
const SHAPE = /^(\d+(?:[\u202f\u00a0\s]\d{3})*(?:[.,]\d+)?)(.*)$/s;

/**
 * @returns {{value: number, decimals: number, group: string, decimal: string, suffix: string}|null}
 */
export function readFigure(text) {
    const match = SHAPE.exec(text.trim());

    if (null === match) {
        return null;
    }

    const [, figure, suffix] = match;
    const decimal = figure.includes(",") ? "," : ".";
    const [whole, fraction = ""] = figure.split(/[.,]/);
    const group = /[  \s]/.exec(whole)?.[0] ?? "";
    const plain = `${whole.replace(/[  \s]/g, "")}.${fraction || "0"}`;
    const value = Number(plain);

    return Number.isFinite(value)
        ? { value, decimals: fraction.length, group, decimal, suffix }
        : null;
}

/** Rendre un nombre comme la page l'avait écrit. */
export function format(value, figure) {
    const fixed = value.toFixed(figure.decimals);
    const [whole, fraction] = fixed.split(".");
    const grouped =
        "" === figure.group
            ? whole
            : whole.replace(/\B(?=(\d{3})+(?!\d))/g, figure.group);

    return `${grouped}${figure.decimals > 0 ? figure.decimal + fraction : ""}${figure.suffix}`;
}

/**
 * La part du chemin parcourue à cet instant.
 *
 * Rapide au début puis freinage long, pour que le nombre se pose sur sa
 * valeur au lieu de s'y arrêter net. Le dernier chiffre est celui qu'on lit.
 */
export function ease(ratio) {
    const clamped = Math.min(Math.max(ratio, 0), 1);

    return 1 - (1 - clamped) ** 3;
}

function run(element, figure) {
    const start = performance.now();

    function step(now) {
        const ratio = (now - start) / DURATION;

        if (ratio >= 1) {
            // La valeur écrite par le serveur, telle quelle : reconstruire la
            // dernière image à partir du nombre risquerait d'en changer le
            // format sur la seule image qui reste affichée.
            element.textContent = element.dataset.countUp;

            return;
        }

        element.textContent = format(figure.value * ease(ratio), figure);
        requestAnimationFrame(step);
    }

    requestAnimationFrame(step);
}

function arm() {
    const figures = Array.from(document.querySelectorAll(SELECTOR));

    if (0 === figures.length) {
        return;
    }

    if (window.matchMedia?.("(prefers-reduced-motion: reduce)").matches) {
        return;
    }

    if (!("IntersectionObserver" in window)) {
        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) {
                    return;
                }

                observer.unobserve(entry.target);

                const figure = readFigure(entry.target.dataset.countUp ?? "");

                if (null === figure) {
                    return;
                }

                run(entry.target, figure);
            });
        },
        { threshold: THRESHOLD },
    );

    figures.forEach((element) => {
        // Ce qui est déjà à l'écran n'a pas de raison de repartir de zéro
        // sous les yeux du lecteur : on l'observe quand même, l'observateur
        // le signalera immédiatement, et compter un chiffre qu'on regarde
        // vaut mieux que le voir sauter. En revanche on ne réécrit jamais
        // rien avant d'y être : le chiffre juste reste affiché jusque-là.
        observer.observe(element);
    });
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
