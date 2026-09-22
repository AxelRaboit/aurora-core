/**
 * A slide's colours, as a canvas can use them.
 *
 * Chart.js paints into a canvas and cannot read a CSS custom property, so every
 * colour has to arrive resolved. `AppChart` reads the *document's* theme tokens
 * for exactly this reason; a slide cannot, because its colours are the deck's
 * and a light deck is routinely drawn inside a dark back office.
 */

/** `#rrggbb` at an opacity, or the value untouched if it is not one. */
export function fade(hex, alpha) {
    const match = /^#([0-9a-f]{6})$/i.exec(String(hex ?? "").trim());

    if (!match) return hex;

    const value = parseInt(match[1], 16);

    return `rgba(${(value >> 16) & 255}, ${(value >> 8) & 255}, ${value & 255}, ${alpha})`;
}

/**
 * One accent, as a ramp of as many tones as there are values to tell apart.
 *
 * Opacity rather than hue: a second hue would be a second colour in a deck
 * whose whole palette is three, and the ramp stays legible on a light ground
 * and on a dark one without knowing which it is.
 */
export function ramp(accent, count) {
    const stops = [1, 0.72, 0.54, 0.42, 0.33, 0.26, 0.2];

    return Array.from({ length: Math.max(count, 1) }, (unused, at) =>
        fade(accent, stops[at % stops.length]),
    );
}

/**
 * A number as somebody typed it, comma or point.
 *
 * "2,4" and "2.4" are the same figure on two keyboards, and a chart that drew
 * one of them as zero would be a chart that lies quietly. Everything that is
 * not a number at all becomes zero, which draws as a missing bar rather than
 * breaking the scale.
 */
export function figure(text) {
    const value = Number(
        String(text ?? "")
            .replace(/\s/g, "")
            .replace(",", "."),
    );

    return Number.isFinite(value) ? value : 0;
}

/** The three channels of `#rrggbb`, or null if it is not one. */
function channels(hex) {
    const match = /^#([0-9a-f]{6})$/i.exec(String(hex ?? "").trim());

    if (!match) return null;

    const value = parseInt(match[1], 16);

    return [(value >> 16) & 255, (value >> 8) & 255, value & 255];
}

/** One channel, linearised, as the contrast formula wants it. */
function linear(channel) {
    const ratio = channel / 255;

    return ratio <= 0.04045 ? ratio / 12.92 : ((ratio + 0.055) / 1.055) ** 2.4;
}

/** Relative luminance, 0 for black and 1 for white. */
export function luminance(hex) {
    const rgb = channels(hex);

    if (!rgb) return null;

    const [r, g, b] = rgb.map(linear);

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/**
 * How far apart two colours are, from 1 to 21.
 *
 * **The counterweight to every option this module has grown.** A wash, a band,
 * an inversion and a per-slide ground are each reasonable on their own, and
 * together they make it easy to write text nobody can read without noticing it
 * on a laptop screen in a lit room. The number is the same one the accessibility
 * guidelines use, so it can be compared to a threshold somebody else decided.
 *
 * Null when either colour is not a plain hex: the panel says nothing rather
 * than guessing, since the value may be a theme token it has no business
 * resolving.
 */
export function contrast(first, second) {
    const one = luminance(first);
    const two = luminance(second);

    if (one === null || two === null) return null;

    const lighter = Math.max(one, two);
    const darker = Math.min(one, two);

    return (lighter + 0.05) / (darker + 0.05);
}

/**
 * What to say about a pair of colours, or nothing at all.
 *
 * Three bands rather than a number in the panel: 4.5 is the threshold for
 * ordinary text and 3 for the large type a slide is mostly made of, so a deck
 * between the two is readable on a wall and marginal in a document. Below 3 it
 * is nobody's idea of readable.
 *
 * It never refuses. A deck is somebody's document and a warning that blocked
 * would be a module deciding it knows better than the person looking at the
 * slide.
 */
export function readability(ink, ground) {
    const ratio = contrast(ink, ground);

    if (ratio === null) return null;

    return {
        ratio: Math.round(ratio * 10) / 10,
        level: ratio >= 4.5 ? "good" : ratio >= 3 ? "large" : "poor",
    };
}

/**
 * A handful of tones taken from a picture, as candidates for an accent.
 *
 * Read from a canvas at a coarse step, because the question is "what colour is
 * this photograph" and not "what is in it": every sixteenth pixel answers that
 * as well as all of them and keeps the work under a frame. Very dark, very
 * pale and very grey samples are dropped, since none of them makes an accent
 * that can be seen against either ground.
 *
 * Returns the most common first. An empty list is a normal answer: a
 * photograph of fog has no accent to offer.
 */
export function tonesFrom(image, wanted = 4) {
    const canvas = document.createElement("canvas");
    const width = (canvas.width = 64);
    const height = (canvas.height = Math.max(
        1,
        Math.round((image.naturalHeight / image.naturalWidth) * 64),
    ));
    const context = canvas.getContext("2d", { willReadFrequently: true });

    if (!context) return [];

    context.drawImage(image, 0, 0, width, height);

    let pixels;

    try {
        pixels = context.getImageData(0, 0, width, height).data;
    } catch {
        // A picture served from another origin taints the canvas and reading it
        // throws. The panel simply offers nothing rather than failing.
        return [];
    }

    const buckets = new Map();

    for (let at = 0; at < pixels.length; at += 4) {
        if (pixels[at + 3] < 200) continue;

        const [r, g, b] = [pixels[at], pixels[at + 1], pixels[at + 2]];
        const max = Math.max(r, g, b);
        const min = Math.min(r, g, b);

        if (max < 40 || min > 215 || max - min < 30) continue;

        const key = [r, g, b]
            .map((channel) => Math.round(channel / 24) * 24)
            .join(",");
        const bucket = buckets.get(key) ?? { count: 0, r: 0, g: 0, b: 0 };

        bucket.count += 1;
        bucket.r += r;
        bucket.g += g;
        bucket.b += b;
        buckets.set(key, bucket);
    }

    return [...buckets.values()]
        .sort((one, two) => two.count - one.count)
        .slice(0, wanted)
        .map(
            ({ count, r, g, b }) =>
                "#" +
                [r, g, b]
                    .map((total) =>
                        Math.round(total / count)
                            .toString(16)
                            .padStart(2, "0"),
                    )
                    .join(""),
        );
}
