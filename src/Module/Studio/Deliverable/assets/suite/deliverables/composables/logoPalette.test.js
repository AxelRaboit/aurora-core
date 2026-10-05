import { describe, expect, it } from "vitest";
import { paletteFromPixels } from "./logoPalette.js";

function pixels(...colours) {
    return new Uint8ClampedArray(
        colours.flatMap(([rgb, times]) =>
            Array.from({ length: times }, () => [...rgb, 255]).flat(),
        ),
    );
}

describe("paletteFromPixels", () => {
    it("keeps the brand colours, most frequent first, and leaves paper and ink out", () => {
        const data = pixels(
            [[255, 255, 255], 500],
            [[10, 10, 10], 300],
            [[189, 74, 85], 120],
            [[47, 27, 234], 60],
            [[128, 128, 128], 80],
        );

        expect(paletteFromPixels(data)).toEqual(["#bd4a55", "#2f1bea"]);
    });

    it("does not offer two shades of the same hue", () => {
        const data = pixels(
            [[189, 74, 85], 100],
            [[200, 80, 90], 90],
            [[16, 185, 129], 20],
        );

        // The two reds share a bucket and come out as their blend.
        expect(paletteFromPixels(data)).toEqual(["#c24d57", "#10b981"]);
    });
});
