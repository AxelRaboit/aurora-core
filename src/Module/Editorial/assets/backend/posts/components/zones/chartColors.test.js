import { describe, expect, it } from "vitest";
import { chartRows, setChartColor } from "./chartColors.js";

describe("chartColors", () => {
    it("reads the colour of each named row, short hex made long", () => {
        expect(
            chartRows("Photos ; 60 ; #2F1BEA\n\nRéels ; 40\nVidéos | 5 | #abc"),
        ).toEqual([
            { index: 0, label: "Photos", color: "#2f1bea" },
            { index: 2, label: "Réels", color: null },
            { index: 3, label: "Vidéos", color: "#aabbcc" },
        ]);
    });

    it("ignores a third cell that is not a colour", () => {
        expect(chartRows("Photos ; 60 ; rouge")[0].color).toBeNull();
    });

    it("writes a colour into the third cell, adding it when missing", () => {
        expect(
            setChartColor(
                "[Photos] ; [60] ; #111111\n[Réels] ; [40]",
                1,
                "#E4F76B",
            ),
        ).toBe("[Photos] ; [60] ; #111111\n[Réels] ; [40] ; #e4f76b");
        expect(setChartColor("[Photos] ; [60] ; #111111", 0, "#222222")).toBe(
            "[Photos] ; [60] ; #222222",
        );
    });

    it("gives a line without a value an empty one rather than shifting the colour into it", () => {
        expect(setChartColor("Photos", 0, "#222222")).toBe(
            "Photos ;  ; #222222",
        );
    });

    it("removes the colour to go back to the theme's shades", () => {
        expect(
            setChartColor("Photos ; 60 ; #2f1bea\nRéels ; 40", 0, null),
        ).toBe("Photos ; 60\nRéels ; 40");
    });
});
