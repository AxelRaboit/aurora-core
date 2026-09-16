/**
 * The shared categorical palette, as slot numbers.
 *
 * Mirrors `Aurora\Core\Support\ChartPalette::MAX_SLOT`, which mirrors the eight
 * `--chart-cat-*` tokens. It lived in three module composables before it lived
 * here, which is how the number ends up disagreeing with itself.
 *
 * A slot is turned into a colour by writing `var(--chart-cat-N)` into a style
 * binding, never by looking a hex value up: the tokens have a step per theme,
 * and resolving one in JavaScript freezes it to the theme that was on at the
 * time.
 */
export const MAX_COLOUR_SLOT = 8;

export const COLOUR_SLOTS = Array.from(
    { length: MAX_COLOUR_SLOT },
    (_, index) => index + 1,
);

/** The CSS value a slot stands for, or null when nothing is chosen. */
export function slotColour(slot) {
    return slot ? `var(--chart-cat-${slot})` : null;
}
