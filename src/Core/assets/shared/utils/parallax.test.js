import { describe, it, expect, vi } from "vitest";

vi.stubGlobal("matchMedia", (query) => ({
    matches: true,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
}));

const { offset } = await import("./parallax.js");

describe("offset", () => {
    it("is zero when the band is halfway through the window", () => {
        expect(offset(250, 500, 1000)).toBeCloseTo(0);
    });

    it("never moves the picture further than its overflow", () => {
        expect(offset(1000, 500, 1000)).toBeCloseTo(-75);
        expect(offset(-500, 500, 1000)).toBeCloseTo(75);
        expect(offset(5000, 500, 1000)).toBeCloseTo(-75);
    });
});
