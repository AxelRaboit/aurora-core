import { describe, it, expect } from "vitest";

const { stepIndex, direction, swipeStep, intervalMs } =
    await import("./bannerCarousel.js");

describe("stepIndex", () => {
    it("moves forward and back", () => {
        expect(stepIndex(0, 1, 4)).toBe(1);
        expect(stepIndex(2, -1, 4)).toBe(1);
    });

    it("loops at both ends", () => {
        expect(stepIndex(3, 1, 4)).toBe(0);
        expect(stepIndex(0, -1, 4)).toBe(3);
    });

    it("stays at zero without slides", () => {
        expect(stepIndex(0, 1, 0)).toBe(0);
    });
});

describe("direction", () => {
    it("follows the step when there is one, even across the loop", () => {
        expect(direction(3, 0, 1)).toBe(1);
        expect(direction(0, 3, -1)).toBe(-1);
    });

    it("follows the dot's place otherwise", () => {
        expect(direction(0, 2)).toBe(1);
        expect(direction(3, 1)).toBe(-1);
    });
});

describe("swipeStep", () => {
    it("goes to the next slide on a swipe to the left", () => {
        expect(swipeStep(-80, 5)).toBe(1);
        expect(swipeStep(80, -5)).toBe(-1);
    });

    it("ignores a tap, and a scroll that drifts sideways", () => {
        expect(swipeStep(-10, 0)).toBe(0);
        expect(swipeStep(-60, 140)).toBe(0);
    });
});

describe("intervalMs", () => {
    it("reads seconds", () => {
        expect(intervalMs("7")).toBe(7000);
    });

    it("keeps within the bounds the server applies, and falls back to seven", () => {
        expect(intervalMs("1")).toBe(3000);
        expect(intervalMs("90")).toBe(30000);
        expect(intervalMs(undefined)).toBe(7000);
    });
});
