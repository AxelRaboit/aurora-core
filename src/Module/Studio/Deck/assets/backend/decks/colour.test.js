import { describe, expect, it } from "vitest";
import { fade, figure, ramp, contrast, readability } from "./colour.js";

describe("fade", () => {
    it("turns a slide colour into something a canvas can use", () => {
        expect(fade("#58a6ff", 0.5)).toBe("rgba(88, 166, 255, 0.5)");
    });

    /** A theme's colour is always six digits, but a hand-edited style is not. */
    it("hands back anything that is not a six-digit hex untouched", () => {
        expect(fade("red", 0.5)).toBe("red");
        expect(fade(null, 0.5)).toBe(null);
    });
});

describe("ramp", () => {
    it("gives one tone per value, all of them the accent", () => {
        const tones = ramp("#58a6ff", 3);

        expect(tones).toHaveLength(3);
        expect(
            tones.every((tone) => tone.startsWith("rgba(88, 166, 255")),
        ).toBe(true);
    });

    it("repeats rather than running out on a long series", () => {
        expect(ramp("#58a6ff", 9)).toHaveLength(9);
    });

    it("never answers an empty ramp", () => {
        expect(ramp("#58a6ff", 0)).toHaveLength(1);
    });
});

describe("figure", () => {
    /** The two separators French and English keyboards produce are one figure. */
    it("reads a comma and a point the same way", () => {
        expect(figure("2,4")).toBe(2.4);
        expect(figure("2.4")).toBe(2.4);
    });

    it("ignores the spaces of a grouped number", () => {
        expect(figure("12 000")).toBe(12000);
    });

    /** Zero draws as a missing bar; NaN breaks the whole scale. */
    it("answers zero for what is not a number at all", () => {
        expect(figure("beaucoup")).toBe(0);
        expect(figure(null)).toBe(0);
    });
});

describe("contrast", () => {
    it("puts black on white at the top of the scale", () => {
        expect(contrast("#000000", "#ffffff")).toBeCloseTo(21, 1);
    });

    it("puts a colour against itself at the bottom", () => {
        expect(contrast("#1a6a5a", "#1a6a5a")).toBeCloseTo(1, 5);
    });

    it("says nothing rather than guessing when a value is not a colour", () => {
        expect(contrast("var(--slide-ink)", "#ffffff")).toBeNull();
    });
});

describe("readability", () => {
    it("bands the answer where the guidelines band it", () => {
        expect(readability("#1a1c20", "#faf8f4").level).toBe("good");
        expect(readability("#767676", "#ffffff").level).toBe("good");
        expect(readability("#949494", "#ffffff").level).toBe("large");
        expect(readability("#c9c9c9", "#ffffff").level).toBe("poor");
    });
});
