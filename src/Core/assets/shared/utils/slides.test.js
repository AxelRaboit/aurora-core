import { describe, expect, it } from "vitest";
import { slideFromHash } from "./slides.js";

describe("slideFromHash", () => {
    it("reads the slide an address names, kept within the deck", () => {
        expect(slideFromHash("#diapo-3", 10)).toBe(2);
        expect(slideFromHash("#diapo-40", 10)).toBe(9);
        expect(slideFromHash("#diapo-0", 10)).toBe(0);
    });

    it("starts at the first slide otherwise", () => {
        expect(slideFromHash("", 4)).toBe(0);
        expect(slideFromHash("#contact", 4)).toBe(0);
    });
});
