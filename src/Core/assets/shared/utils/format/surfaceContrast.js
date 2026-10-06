/**
 * Client-side mirror of the PHP `SurfaceContrast` service.
 *
 * The duplication is deliberate and limited: the rendering of the public
 * frontend is decided on the server side, by the PHP service, which remains
 * the reference. This file only exists for the live preview of the theme
 * screen, where waiting for a server round trip on every move of the colour
 * picker would make the visual feedback unusable.
 *
 * Any fix to the calculation must be carried to both sides. The two test
 * suites deliberately share the same reference colours, so that a
 * divergence shows.
 */

/** @param {string} hex @returns {[number, number, number]} */
function hexToRgb(hex) {
    let value = String(hex ?? "")
        .trim()
        .replace(/^#/, "");

    if (value.length === 3) {
        value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
    }

    if (value.length !== 6 || !/^[0-9a-f]{6}$/i.test(value)) {
        // Falls back to white, so to the light set: the historical look of the
        // frontend, the least surprising in front of an incomplete input.
        return [255, 255, 255];
    }

    return [
        Number.parseInt(value.slice(0, 2), 16),
        Number.parseInt(value.slice(2, 4), 16),
        Number.parseInt(value.slice(4, 6), 16),
    ];
}

function toLinear(channel) {
    return channel <= 0.04045
        ? channel / 12.92
        : ((channel + 0.055) / 1.055) ** 2.4;
}

/** WCAG relative luminance: 0 for black, 1 for white. */
function relativeLuminance(hex) {
    const [r, g, b] = hexToRgb(hex);

    return (
        0.2126 * toLinear(r / 255) +
        0.7152 * toLinear(g / 255) +
        0.0722 * toLinear(b / 255)
    );
}

/** WCAG contrast ratio between two colours, from 1 to 21. */
export function contrastRatio(hexA, hexB) {
    const a = relativeLuminance(hexA);
    const b = relativeLuminance(hexB);
    const [lighter, darker] = a > b ? [a, b] : [b, a];

    return (lighter + 0.05) / (darker + 0.05);
}

/** Does this background call for light text? */
export function needsLightText(hex) {
    return contrastRatio(hex, "#ffffff") > contrastRatio(hex, "#000000");
}

/** The best ratio reachable on this background, white or black alike. */
export function bestContrastRatio(hex) {
    return Math.max(
        contrastRatio(hex, "#ffffff"),
        contrastRatio(hex, "#000000"),
    );
}

/**
 * WCAG AAA threshold for body text.
 *
 * It is AAA and not AA because AA cannot fail: by always keeping the better
 * of black and white, the ratio never drops below 4.608:1, on the grey
 * `#757575`. Flagging AA would amount to showing a warning that never lights
 * up.
 */
export const AAA_NORMAL_TEXT = 7;

export function meetsAaa(hex) {
    return bestContrastRatio(hex) >= AAA_NORMAL_TEXT;
}
