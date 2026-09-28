/**
 * Les diapositives d'un en-tête qui tournent - `[data-banner-carousel]`.
 *
 * Le gabarit empile toutes les diapositives au même endroit et n'en montre
 * qu'une, marquée `data-active` ; sans ce module, c'est la première, et les
 * commandes restent cachées. Ce module ajoute :
 * - le passage automatique, toutes les `data-interval` secondes, quand
 *   `data-autoplay` le demande, arrêté sous le pointeur (`data-pause-on-hover`),
 *   quand une commande a le focus, quand l'onglet est caché, et par le bouton
 *   pause ;
 * - les flèches, les points, les flèches du clavier et le glissement au doigt ;
 * - la couleur du point courant, prise sur l'accent de la diapositive.
 *
 * Quelqu'un qui a demandé moins d'animations à son système n'a pas de
 * passage automatique : il garde les commandes.
 *
 * Gabarit : templates/Frontend/themes/default/editorial/post/_banner.html.twig
 */
const SELECTOR = "[data-banner-carousel]";

/** Distance, en pixels, au-delà de laquelle un glissement change de diapositive. */
export const SWIPE_THRESHOLD = 40;

/** L'indice atteint en avançant de `step` depuis `current`, en bouclant. */
export function stepIndex(current, step, count) {
    if (count <= 0) {
        return 0;
    }

    return (((current + step) % count) + count) % count;
}

/**
 * Le sens d'un passage de `from` à `to` : 1 vers la droite, -1 vers la
 * gauche. Un point cliqué va dans le sens de sa place ; un pas au-delà du
 * bout, qui boucle, garde le sens du pas.
 */
export function direction(from, to, step = 0) {
    if (0 !== step) {
        return step > 0 ? 1 : -1;
    }

    return to >= from ? 1 : -1;
}

/** Le pas demandé par un glissement horizontal, ou 0 s'il est trop court ou trop vertical. */
export function swipeStep(dx, dy, threshold = SWIPE_THRESHOLD) {
    if (Math.abs(dx) < threshold || Math.abs(dx) < Math.abs(dy)) {
        return 0;
    }

    return dx < 0 ? 1 : -1;
}

/** La durée d'une diapositive en millisecondes, bornée comme au serveur. */
export function intervalMs(raw) {
    const seconds = Number.parseInt(raw, 10);

    return (
        (Number.isFinite(seconds) ? Math.min(30, Math.max(3, seconds)) : 7) *
        1000
    );
}

function wire(root) {
    const slides = [...root.querySelectorAll("[data-banner-slide]")];
    const dots = [...root.querySelectorAll("[data-banner-dot]")];
    const controls = root.querySelector("[data-banner-controls]");
    const prev = root.querySelector("[data-banner-prev]");
    const next = root.querySelector("[data-banner-next]");
    const pause = root.querySelector("[data-banner-pause]");

    if (slides.length < 2) {
        return;
    }

    const reducedMotion =
        window.matchMedia?.("(prefers-reduced-motion: reduce)").matches ??
        false;
    const autoplay = "true" === root.dataset.autoplay && !reducedMotion;
    const pauseOnHover = "true" === root.dataset.pauseOnHover;
    const sliding = "slide" === root.dataset.transition;
    const delay = intervalMs(root.dataset.interval);

    let current = Math.max(
        0,
        slides.findIndex((slide) => slide.hasAttribute("data-active")),
    );
    let timer = 0;
    let hovered = false;
    let focused = false;
    let stopped = false;

    const accentOf = (slide) =>
        getComputedStyle(slide).getPropertyValue("--th-accent").trim();

    function paint() {
        const accent = accentOf(slides[current]);
        if (accent) {
            root.style.setProperty("--banner-dot-color", accent);
        }

        dots.forEach((dot, index) => {
            if (index === current) {
                dot.setAttribute("aria-current", "true");
            } else {
                dot.removeAttribute("aria-current");
            }
        });
    }

    function show(target, dir) {
        if (target === current) {
            return;
        }

        const leaving = slides[current];
        const entering = slides[target];

        if (sliding) {
            // L'entrante est posée du côté d'où elle vient, sans transition,
            // puis relâchée : c'est ce qui la fait glisser dans le bon sens.
            entering.setAttribute("data-banner-staging", "");
            entering.style.setProperty("--banner-to", `${dir * 100}%`);
            void entering.offsetWidth;
            entering.removeAttribute("data-banner-staging");
            leaving.style.setProperty("--banner-to", `${-dir * 100}%`);
        }

        leaving.removeAttribute("data-active");
        leaving.setAttribute("aria-hidden", "true");
        leaving.inert = true;

        entering.setAttribute("data-active", "");
        entering.removeAttribute("aria-hidden");
        entering.inert = false;

        current = target;
        paint();
    }

    function go(step) {
        show(
            stepIndex(current, step, slides.length),
            direction(current, 0, step),
        );
        restart();
    }

    function goTo(target) {
        show(target, direction(current, target));
        restart();
    }

    function running() {
        return (
            autoplay &&
            !stopped &&
            !focused &&
            !(pauseOnHover && hovered) &&
            !document.hidden
        );
    }

    function restart() {
        clearTimeout(timer);
        if (running()) {
            timer = setTimeout(() => {
                show(stepIndex(current, 1, slides.length), 1);
                restart();
            }, delay);
        }
    }

    if (controls) {
        controls.hidden = false;
    }
    [prev, next].forEach((button) => {
        if (button) {
            button.hidden = false;
        }
    });

    prev?.addEventListener("click", () => go(-1));
    next?.addEventListener("click", () => go(1));
    dots.forEach((dot) =>
        dot.addEventListener("click", () =>
            goTo(Number(dot.dataset.bannerDot)),
        ),
    );

    if (pause) {
        if (!autoplay) {
            // Rien à arrêter : pas de passage automatique demandé, ou le
            // système en a demandé moins.
            pause.hidden = true;
        }

        pause.addEventListener("click", () => {
            stopped = !stopped;
            pause.setAttribute(
                "aria-label",
                stopped ? pause.dataset.labelPlay : pause.dataset.labelPause,
            );
            pause
                .querySelector("[data-icon-pause]")
                ?.toggleAttribute("hidden", stopped);
            pause
                .querySelector("[data-icon-play]")
                ?.toggleAttribute("hidden", !stopped);
            restart();
        });
    }

    root.addEventListener("mouseenter", () => {
        hovered = true;
        restart();
    });
    root.addEventListener("mouseleave", () => {
        hovered = false;
        restart();
    });
    root.addEventListener("focusin", () => {
        focused = true;
        restart();
    });
    root.addEventListener("focusout", (event) => {
        if (!root.contains(event.relatedTarget)) {
            focused = false;
            restart();
        }
    });
    document.addEventListener("visibilitychange", restart);

    root.addEventListener("keydown", (event) => {
        if ("ArrowLeft" === event.key) {
            event.preventDefault();
            go(-1);
        } else if ("ArrowRight" === event.key) {
            event.preventDefault();
            go(1);
        }
    });

    // Au doigt et au stylet seulement : à la souris, un glisser sélectionne
    // le texte du titre, et les flèches sont là.
    let start = null;
    root.addEventListener(
        "pointerdown",
        (event) => {
            if ("mouse" !== event.pointerType) {
                start = { x: event.clientX, y: event.clientY };
            }
        },
        { passive: true },
    );
    root.addEventListener(
        "pointerup",
        (event) => {
            if (null === start) {
                return;
            }

            const step = swipeStep(
                event.clientX - start.x,
                event.clientY - start.y,
            );
            start = null;
            if (0 !== step) {
                go(step);
            }
        },
        { passive: true },
    );
    root.addEventListener("pointercancel", () => {
        start = null;
    });

    paint();
    restart();
}

function arm() {
    document.querySelectorAll(SELECTOR).forEach(wire);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
