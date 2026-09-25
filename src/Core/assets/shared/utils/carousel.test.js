import { describe, it, expect } from "vitest";

const { currentIndex } = await import("./carousel.js");

describe("currentIndex", () => {
    it("names the slide closest to the left edge", () => {
        expect(currentIndex(0, 600, 5)).toBe(0);
        expect(currentIndex(620, 600, 5)).toBe(1);
        expect(currentIndex(1480, 600, 5)).toBe(2);
    });

    it("stays within the slides", () => {
        expect(currentIndex(99999, 600, 5)).toBe(4);
        expect(currentIndex(-40, 600, 5)).toBe(0);
        expect(currentIndex(100, 0, 5)).toBe(0);
    });
});
