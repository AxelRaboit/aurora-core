/**
 * The arrows and the counter of a carousel - `[data-carousel]`.
 *
 * The scrolling itself is the browser's (scroll snapping): it works with a
 * finger, a touchpad and the keyboard without this module. This one adds the
 * arrows, keeps the counter up to date while scrolling, and stays silent
 * without JavaScript, where the arrows stay hidden.
 *
 * Template: templates/Frontend/themes/default/editorial/post/zones/_carousel.html.twig
 */
const SELECTOR = "[data-carousel]";

/** The visible slide: the one whose start is closest to the left edge. */
export function currentIndex(scrollLeft, slideWidth, count) {
    if (slideWidth <= 0 || count <= 0) {
        return 0;
    }

    return Math.min(
        count - 1,
        Math.max(0, Math.round(scrollLeft / slideWidth)),
    );
}

function wire(carousel) {
    const track = carousel.querySelector("[data-carousel-track]");
    const controls = carousel.querySelector("[data-carousel-controls]");
    const counter = carousel.querySelector("[data-carousel-counter]");

    if (!track || !controls) {
        return;
    }

    const slides = track.children.length;
    const index = () =>
        currentIndex(track.scrollLeft, track.clientWidth, slides);
    const go = (step) => {
        const target = Math.min(slides - 1, Math.max(0, index() + step));
        track.scrollTo({ left: target * track.clientWidth });
    };

    controls.hidden = false;
    carousel
        .querySelector("[data-carousel-prev]")
        ?.addEventListener("click", () => go(-1));
    carousel
        .querySelector("[data-carousel-next]")
        ?.addEventListener("click", () => go(1));

    track.addEventListener("keydown", (event) => {
        if ("ArrowLeft" === event.key) {
            event.preventDefault();
            go(-1);
        } else if ("ArrowRight" === event.key) {
            event.preventDefault();
            go(1);
        }
    });

    let frame = 0;
    track.addEventListener(
        "scroll",
        () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(() => {
                if (counter) {
                    counter.textContent = `${index() + 1} / ${slides}`;
                }
            });
        },
        { passive: true },
    );
}

function arm() {
    document.querySelectorAll(SELECTOR).forEach(wire);
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
