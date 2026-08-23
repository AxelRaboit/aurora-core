import { describe, expect, it } from "vitest";
import {
    COLOUR_SLOTS,
    MAX_COLOUR_SLOT,
    nextFreeColourSlot,
} from "./calendarColours.js";

describe("calendarColours", () => {
    it("takes the first slot nobody is using", () => {
        expect(nextFreeColourSlot([{ colourSlot: 1 }, { colourSlot: 2 }])).toBe(
            3,
        );
    });

    it("fills a gap rather than always walking to the end", () => {
        expect(nextFreeColourSlot([{ colourSlot: 1 }, { colourSlot: 3 }])).toBe(
            2,
        );
    });

    it("starts at the first slot when there is nothing yet", () => {
        // The case a fresh installation hits, which until now nobody could reach:
        // there was no way to make a first calendar from the screen.
        expect(nextFreeColourSlot([])).toBe(1);
        expect(nextFreeColourSlot(undefined)).toBe(1);
    });

    it("repeats a colour once all eight are taken", () => {
        // Sharing with an existing calendar beats a ninth colour nobody can tell
        // from the first - the palette's ceiling is a colour-vision decision, not
        // an arbitrary number.
        const full = COLOUR_SLOTS.map((slot) => ({ colourSlot: slot }));

        expect(nextFreeColourSlot(full)).toBe(1);
    });

    it("offers exactly the palette", () => {
        expect(COLOUR_SLOTS).toEqual([1, 2, 3, 4, 5, 6, 7, 8]);
        expect(COLOUR_SLOTS).toHaveLength(MAX_COLOUR_SLOT);
    });
});
