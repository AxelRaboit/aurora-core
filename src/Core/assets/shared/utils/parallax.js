/**
 * Le bandeau d'image qui défile plus lentement que la page - `[data-parallax]`.
 *
 * L'image est plus haute que son cadre (130 %) ; ce module la décale au fil du
 * défilement, d'au plus ce surplus, pour qu'aucun bord n'apparaisse. Immobile
 * pour qui a demandé moins d'animations : le bandeau reste une image recadrée
 * avec sa phrase, ce qui suffit.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/_grid_zone.html.twig
 */
const SELECTOR = "[data-parallax]";

/** Le surplus de l'image, de part et d'autre du cadre, en fraction de sa hauteur. */
const TRAVEL = 0.15;

/** Décalage en pixels pour un cadre à `top` dans une fenêtre de `viewport` pixels. */
export function offset(top, height, viewport) {
    const progress = (viewport - top) / (viewport + height);
    const clamped = Math.min(1, Math.max(0, progress));

    return (clamped - 0.5) * 2 * TRAVEL * height;
}

function arm() {
    const bands = [...document.querySelectorAll(SELECTOR)];

    if (
        0 === bands.length ||
        window.matchMedia?.("(prefers-reduced-motion: reduce)").matches
    ) {
        return;
    }

    let frame = 0;
    const update = () => {
        frame = 0;
        const viewport = window.innerHeight;

        for (const band of bands) {
            const rect = band.getBoundingClientRect();

            if (rect.bottom < 0 || rect.top > viewport) {
                continue;
            }

            const layer = band.querySelector("[data-parallax-layer]");

            if (layer) {
                layer.style.transform = `translate3d(0, ${offset(rect.top, rect.height, viewport).toFixed(1)}px, 0)`;
            }
        }
    };

    const schedule = () => {
        if (0 === frame) {
            frame = requestAnimationFrame(update);
        }
    };

    window.addEventListener("scroll", schedule, { passive: true });
    window.addEventListener("resize", schedule);
    update();
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
