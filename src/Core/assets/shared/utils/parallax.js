/**
 * The image band that scrolls more slowly than the page - `[data-parallax]`.
 *
 * The image is taller than its frame (130%); this module shifts it as the
 * page scrolls, by at most that surplus, so that no edge shows. Still for
 * whoever asked for fewer animations: the band stays a cropped image with
 * its sentence, which is enough.
 *
 * Template: templates/Frontend/themes/default/editorial/post/_grid_zone.html.twig
 */
const SELECTOR = "[data-parallax]";

/** The surplus of the image, on either side of the frame, as a fraction of its height. */
const TRAVEL = 0.15;

/** Offset in pixels for a frame at `top` in a window of `viewport` pixels. */
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
