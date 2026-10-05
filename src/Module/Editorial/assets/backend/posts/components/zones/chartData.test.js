import { describe, expect, it } from "vitest";
import { formatChartRows, parseChartRows } from "./chartData.js";

describe("chartData", () => {
    it("reads one row per line, either separator, short hex made long", () => {
        expect(
            parseChartRows(
                "Photos ; 60 ; #2F1BEA\n\n[Réels] ; [40]\nVidéos | 5 | #abc",
            ),
        ).toEqual([
            { label: "Photos", value: "60", color: "#2f1bea" },
            { label: "[Réels]", value: "[40]", color: null },
            { label: "Vidéos", value: "5", color: "#aabbcc" },
        ]);
    });

    it("drops a third cell that is not a colour", () => {
        expect(parseChartRows("Photos ; 60 ; rouge")[0].color).toBeNull();
    });

    it("writes the rows back, the colour only when there is one", () => {
        expect(
            formatChartRows([
                { label: "[Photos uniques]", value: "[60]", color: "#2f1bea" },
                { label: "[Réels]", value: "[15]", color: null },
            ]),
        ).toBe("[Photos uniques] ; [60] ; #2f1bea\n[Réels] ; [15]");
    });

    it("skips a blank row and keeps a row still missing its value", () => {
        expect(
            formatChartRows([
                { label: " Photos ", value: "", color: null },
                { label: "", value: "", color: "#111111" },
            ]),
        ).toBe("Photos");
    });

    it("reads back what it writes", () => {
        const code = "[Photos] ; [60] ; #2f1bea\nCarrousels ; 1 234,5";

        expect(formatChartRows(parseChartRows(code))).toBe(code);
    });
});
