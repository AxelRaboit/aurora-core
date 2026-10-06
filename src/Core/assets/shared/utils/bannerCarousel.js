/**
 * The slides of a banner that rotate - `[data-banner-carousel]`.
 *
 * The template stacks every slide in the same place and shows only one,
 * marked `data-active`; without this module, it is the first one, and the
 * controls stay hidden. This module adds:
 * - automatic advance, every `data-interval` seconds, when `data-autoplay`
 *   asks for it, stopped under the pointer (`data-pause-on-hover`), when a
 *   control has focus, when the tab is hidden, and by the pause button;
 * - the arrows, the dots, the keyboard arrows and the finger swipe;
 * - the colour of the current dot, taken from the slide's accent.
 *
 * Someone who asked their system for fewer animations gets no automatic
 * advance: they keep the controls.
 *
 * Template: templates/Frontend/themes/default/editorial/post/_banner.html.twig
 */
const SELECTOR = "[data-banner-carousel]";

/** Distance, in pixels, beyond which a swipe changes slide. */
export const SWIPE_THRESHOLD = 40;

/** The index reached by moving `step` from `current`, wrapping around. */
export function stepIndex(current, step, count) {
    if (count <= 0) {
        return 0;
    }

    return (((current + step) % count) + count) % count;
}

/**
 * The direction of a move from `from` to `to`: 1 to the right, -1 to the
 * left. A clicked dot goes in the direction of its position; a step past the
 * end, which wraps around, keeps the direction of the step.
 */
export function direction(from, to, step = 0) {
    if (0 !== step) {
        return step > 0 ? 1 : -1;
    }

    return to >= from ? 1 : -1;
}

/** The step a horizontal swipe asks for, or 0 when it is too short or too vertical. */
export function swipeStep(dx, dy, threshold = SWIPE_THRESHOLD) {
    if (Math.abs(dx) < threshold || Math.abs(dx) < Math.abs(dy)) {
        return 0;
    }

    return dx < 0 ? 1 : -1;
}

/** The duration of a slide in milliseconds, bounded as on the server. */
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
            // The incoming slide is placed on the side it comes from, without a
            // transition, then released: that is what makes it slide the right way.
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
            // Nothing to stop: no automatic advance was asked for, or the system asked
            // for less of it.
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

    // Finger and stylus only: with the mouse, a drag selects the title text,
    // and the arrows are there.
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
