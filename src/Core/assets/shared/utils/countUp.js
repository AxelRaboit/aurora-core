/**
 * A figure that climbs to its value when the reader reaches it.
 *
 * **The final value is already in the HTML**, and that constraint decides
 * everything else: this file reads it, counts up to it, and puts it back.
 * Script in error, JavaScript turned off, search engine, screen reader: the
 * right figure is there, written by the server. Starting from zero in the
 * markup would have put a wrong figure on the page for everything that does
 * not play the animation.
 *
 * **What is not a number is not touched.** The value of a figure is free
 * text: "24", but also "3 languages", "+40 %", "2 to 3". Only a leading number,
 * possibly followed by a unit, is counted; everything else shows as is.
 * Animating nothing is better than animating wrong.
 *
 * The format comes from the page: thousands separator, decimal comma, number
 * of decimals are read back from the written value, not guessed from a
 * locale. The server wrote "1 250", there is no reason to reinvent how.
 *
 * Markup: templates/Frontend/themes/default/editorial/post/_grid_items.html.twig
 */
const SELECTOR = "[data-count-up]";

/** Enough to see it climb, little enough not to keep anyone waiting. */
const DURATION = 900;

const THRESHOLD = 0.4;

/**
 * What can be read: a leading number, the rest kept.
 *
 * The spaces accepted as thousands separators include the narrow no-break
 * space (U+202F), the one PHP writes in French, and the ordinary no-break
 * space (U+00A0) - without them "1 250" would read as "1".
 *
 * **A separator only counts when followed by three digits.** Without that
 * requirement the expression also swallowed the space that separates a
 * number from its unit: "3 languages" gave the number 3 and the rest
 * "languages", which was shown again glued on, "3languages".
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

/** Render a number the way the page had written it. */
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
 * The share of the way covered at this moment.
 *
 * Fast at first, then a long braking, so that the number settles on its
 * value instead of stopping dead on it. The last figure is the one people
 * read.
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
            // The value written by the server, as is: rebuilding the last frame from
            // the number could change its format on the only frame that stays
            // displayed.
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
        // What is already on screen has no reason to start again from zero under
        // the reader's eyes: it is observed anyway, the observer will report it
        // immediately, and counting a figure being watched is better than seeing it
        // jump. On the other hand nothing is ever rewritten before getting there:
        // the right figure stays displayed until then.
        observer.observe(element);
    });
}

if ("loading" === document.readyState) {
    document.addEventListener("DOMContentLoaded", arm);
} else {
    arm();
}
