/**
 * The calendar palette, and picking a free slot from it.
 *
 * `MAX_COLOUR_SLOT` mirrors AbstractPlanning::MAX_COLOUR_SLOT, which mirrors the
 * eight `--chart-cat-*` tokens. A test asserts the three agree - eight is the
 * palette's real ceiling, checked for colour-vision separation, and a ninth
 * colour nobody can tell from the first would be worse than a repeat.
 */
export const MAX_COLOUR_SLOT = 8;

export const COLOUR_SLOTS = Array.from(
    { length: MAX_COLOUR_SLOT },
    (_, i) => i + 1,
);

/**
 * The first slot none of these calendars is using.
 *
 * Computed from the calendars on screen rather than asked of the server, and the
 * difference is what "free" means: a slot taken by a calendar this reader cannot
 * see is not a collision they can perceive, and a value fetched once would go
 * stale the moment they make a second calendar without reloading.
 *
 * Falls back to the first slot once all eight are taken. Sharing a colour with
 * an existing calendar is the honest outcome at that point.
 *
 * @param {Array<{colourSlot: number}>} calendars
 * @returns {number}
 */
export function nextFreeColourSlot(calendars) {
    const taken = new Set(
        (calendars ?? []).map((calendar) => calendar.colourSlot),
    );

    return COLOUR_SLOTS.find((slot) => !taken.has(slot)) ?? 1;
}
