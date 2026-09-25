import { describe, it, expect } from "vitest";

const { remaining } = await import("./countdown.js");

describe("remaining", () => {
    it("splits what is left into days, hours, minutes and seconds", () => {
        const now = Date.UTC(2026, 10, 13, 17, 30, 0);
        const target = Date.UTC(2026, 10, 14, 19, 45, 30);

        expect(remaining(target, now)).toEqual({
            passed: false,
            days: 1,
            hours: 2,
            minutes: 15,
            seconds: 30,
        });
    });

    it("never goes below zero once the moment has passed", () => {
        expect(remaining(1000, 5000)).toEqual({
            passed: true,
            days: 0,
            hours: 0,
            minutes: 0,
            seconds: 0,
        });
    });
});
