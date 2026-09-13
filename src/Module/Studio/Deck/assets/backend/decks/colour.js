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
