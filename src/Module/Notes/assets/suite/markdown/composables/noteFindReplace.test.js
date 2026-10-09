import { describe, it, expect } from "vitest";
import { findMatches, matchIndexFrom, replaceEvery, replaceOne } from "./noteFindReplace.js";

describe("noteFindReplace", () => {
    it("finds without accents nor case by default", () => {
        const text = "Échéance lundi, echeance mardi";

        expect(findMatches(text, "echeance")).toEqual([
            { start: 0, end: 8 },
            { start: 16, end: 24 },
        ]);
    });

    it("finds the text as typed when exact", () => {
        expect(findMatches("Échéance, echeance", "echeance", { exact: true })).toEqual([
            { start: 10, end: 18 },
        ]);
    });

    it("counts in the units a textarea selects with, after an emoji", () => {
        const text = "🚀 départ";
        const [match] = findMatches(text, "depart");

        expect(text.slice(match.start, match.end)).toBe("départ");
    });

    it("finds nothing for an empty query", () => {
        expect(findMatches("texte", "")).toEqual([]);
    });

    it("starts from the match at the caret, and goes round", () => {
        const matches = [{ start: 2, end: 4 }, { start: 10, end: 12 }];

        expect(matchIndexFrom(matches, 5)).toBe(1);
        expect(matchIndexFrom(matches, 11)).toBe(0);
        expect(matchIndexFrom([], 0)).toBe(-1);
    });

    it("replaces one match, or all of them", () => {
        const text = "le vert, le gris";
        const matches = findMatches(text, "le", { exact: true });

        expect(replaceOne(text, matches[0], "un")).toBe("un vert, le gris");
        expect(replaceEvery(text, matches, "un")).toBe("un vert, un gris");
    });
});
