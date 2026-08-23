export function seoCounterClass(length, max) {
    if (length === 0) return "text-muted";
    if (length <= max * 0.85) return "text-green-500";
    if (length <= max) return "text-amber-500";
    return "text-red-500";
}

/**
 * Google cuts a snippet at a pixel width, not a character count, so the same
 * 160 characters fit or do not depending on what they are made of: "ÉÉÉ…" runs
 * out of room long before "iii…". Counting characters therefore turns amber on
 * text that displays in full, and stays green on text that gets an ellipsis.
 *
 * Widths and fonts of the desktop SERP, which is what the SEO tooling settled
 * on (Yoast, Screaming Frog).
 */
export const SERP_PIXEL_LIMITS = { title: 600, description: 920 };

const SERP_FONTS = {
    title: "20px Arial, sans-serif",
    description: "14px Arial, sans-serif",
};

let measuringContext;

/**
 * @returns {?number} width in pixels, or null where nothing can measure text
 *                    (no DOM, or jsdom without a canvas backend)
 */
export function seoPixelWidth(text, kind) {
    if (measuringContext === undefined) {
        measuringContext =
            typeof document === "undefined"
                ? null
                : (document.createElement("canvas").getContext("2d") ?? null);
    }
    if (!measuringContext) return null;

    measuringContext.font = SERP_FONTS[kind];

    return measuringContext.measureText(String(text ?? "")).width;
}

/**
 * Colour for an SEO field's counter: by measured width when the environment can
 * measure, by character count otherwise. The counter keeps showing characters -
 * that is the number an author can act on - while the colour tells the truth
 * about whether the text will actually fit.
 */
export function seoFieldClass(text, kind, maxChars) {
    const width = seoPixelWidth(text, kind);

    return null === width
        ? seoCounterClass(String(text ?? "").length, maxChars)
        : seoCounterClass(width, SERP_PIXEL_LIMITS[kind]);
}
