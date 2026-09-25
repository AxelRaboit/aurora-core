/**
 * Le mur de vidéos verticales - `[data-video-wall]`.
 *
 * Chaque film joue sans le son tant qu'il est à l'écran et s'arrête dès qu'il
 * n'y est plus : un mur de douze films qui tournent tous à la fois hors champ
 * coûte la batterie d'un téléphone pour rien. Un appui rend le son et les
 * commandes au film touché. Rien ne démarre seul pour qui a demandé moins
 * d'animations ; les films restent là, à lancer d'un appui.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/zones/_video_wall.html.twig
 */
const SELECTOR = "[data-video-wall-film]";

/**
 * Le film derrière une section sur vidéo : même règle, lancé à l'écran et
 * arrêté hors champ, mais sans son ni commandes à rendre - il est le décor.
 * L'attribut `autoplay` ne suffit pas : un onglet en arrière-plan ou un
 * navigateur économe le laisse à l'arrêt sans rien dire.
 */
const BACKGROUND = "[data-background-film]";

function arm() {
    const films = [...document.querySelectorAll(SELECTOR)];
    const backgrounds = [...document.querySelectorAll(BACKGROUND)];

    if (0 === films.length && 0 === backgrounds.length) {
        return;
    }

    for (const film of films) {
        film.addEventListener("click", () => {
            film.muted = false;
            film.controls = true;
            void film.play().catch(() => {});
        });
    }

    if (
        window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ||
        !("IntersectionObserver" in window)
    ) {
        films.forEach((film) => {
            film.controls = true;
        });

        return;
    }

    const observer = new IntersectionObserver(
        (entries) => {
            for (const { target, isIntersecting } of entries) {
                if (isIntersecting) {
                    void target.play().catch(() => {});
                } else if (!target.paused) {
                    target.pause();
                }
            }
        },
        { threshold: 0.5 },
    );

    [...films, ...backgrounds].forEach((film) => observer.observe(film));
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
