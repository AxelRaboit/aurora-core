import { describe, it, expect } from "vitest";

const { summarise } = await import("./quoteEstimator.js");

describe("summarise", () => {
    it("adds the base price to every checked option", () => {
        const result = summarise(
            90,
            [
                { label: "Drone", price: 40 },
                { label: "Album", price: 60 },
            ],
            "€",
        );

        expect(result.total).toBe(190);
        expect(result.text).toBe(
            "- Drone (+40€)\n- Album (+60€)\nTotal : 190€",
        );
    });

    it("is just the base with nothing ticked", () => {
        expect(summarise(90, [], "€").total).toBe(90);
    });
});
