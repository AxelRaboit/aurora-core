/**
 * The wall of vertical videos - `[data-video-wall]`.
 *
 * Each film plays muted while it is on screen and stops as soon as it is no
 * longer there: a wall of twelve films all running at once off screen costs
 * a phone its battery for nothing. A tap gives the sound and the controls
 * back to the film touched. Nothing starts on its own for whoever asked for
 * fewer animations; the films stay there, to start with a tap.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_video_wall.html.twig
 */
const SELECTOR = "[data-video-wall-film]";

/**
 * The film behind a section on video: same rule, started on screen and
 * stopped off screen, but with no sound or controls to give back - it is the
 * scenery. The `autoplay` attribute is not enough: a background tab or a
 * frugal browser leaves it stopped without a word.
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
