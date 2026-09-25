import { describe, it, expect } from "vitest";
import { formatRanges, parseLines, parseRanges } from "./openingHours.js";

describe("parseRanges", () => {
    it("reads ranges written the way people write them", () => {
        expect(parseRanges("9-12, 14h-18h30")).toEqual([
            ["09:00", "12:00"],
            ["14:00", "18:30"],
        ]);
        expect(parseRanges("09:00 – 12:30 et 14:00 – 19:00")).toEqual([
            ["09:00", "12:30"],
            ["14:00", "19:00"],
        ]);
    });

    it("keeps midnight as a closing time", () => {
        expect(parseRanges("18:00-24:00")).toEqual([["18:00", "24:00"]]);
    });

    it("drops what cannot be a range rather than guessing", () => {
        expect(parseRanges("fermé")).toEqual([]);
        expect(parseRanges("18-9")).toEqual([]);
        expect(parseRanges("25-26")).toEqual([]);
    });
});

describe("formatRanges", () => {
    it("writes the stored pairs back for the field", () => {
        expect(
            formatRanges([
                ["09:00", "12:00"],
                ["14:00", "18:00"],
            ]),
        ).toBe("09:00-12:00, 14:00-18:00");
        expect(formatRanges([])).toBe("");
    });
});

describe("parseLines", () => {
    it("keeps one value per non-empty line", () => {
        expect(
            parseLines(
                "AxelRaboit/aurora-core\n\n  AxelRaboit/aurora-client \n",
            ),
        ).toEqual(["AxelRaboit/aurora-core", "AxelRaboit/aurora-client"]);
    });
});
