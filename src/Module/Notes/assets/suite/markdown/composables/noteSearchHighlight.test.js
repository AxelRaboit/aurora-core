import { describe, it, expect } from "vitest";
import {
    findRanges,
    foldSearch,
    highlightParts,
    markTerms,
} from "./noteSearchHighlight.js";

describe("noteSearchHighlight", () => {
    it("folds accents and case", () => {
        expect(foldSearch("Échéance ÉTÉ")).toBe("echeance ete");
    });

    it("finds ranges on the original letters, merged", () => {
        expect(
            findRanges("Une séance à la lumière", ["seance", "lumiere"]),
        ).toEqual([
            [4, 6],
            [16, 7],
        ]);
        expect(findRanges("abcd", ["abc", "bcd"])).toEqual([[0, 4]]);
    });

    it("cuts a text into plain and marked parts", () => {
        expect(highlightParts("la séance", [[3, 6]])).toEqual([
            { text: "la ", mark: false },
            { text: "séance", mark: true },
        ]);
    });

    it("marks a rendered note, and replaces the former marks", () => {
        const root = document.createElement("div");
        root.innerHTML = "<p>Une <strong>séance</strong> de seance</p>";
        // jsdom has no layout, hence no scrolling.
        Element.prototype.scrollIntoView ??= () => {};

        expect(markTerms(root, ["seance"])).toBe(2);
        expect(markTerms(root, ["une"])).toBe(1);
        expect(root.querySelectorAll("mark.md-search-mark")).toHaveLength(1);
        expect(root.textContent).toBe("Une séance de seance");
    });
});
