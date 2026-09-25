import { describe, it, expect } from "vitest";

const { logoBox, LOGO_SHARE } = await import("./qrCode.js");

describe("logoBox", () => {
    it("centres a square that never covers more than its share of the code", () => {
        const box = logoBox(1024);

        expect(box.box).toBe(Math.round(1024 * LOGO_SHARE));
        expect(box.x).toBe(box.y);
        expect(box.x * 2 + box.box).toBeCloseTo(1024, -1);
        expect(box.image).toBe(box.box - 2 * box.padding);
    });

    it("stays within what the highest error correction can read through", () => {
        // H restores up to 30 % of the code; the square hides its share squared.
        expect(LOGO_SHARE * LOGO_SHARE).toBeLessThan(0.3);
    });
});
