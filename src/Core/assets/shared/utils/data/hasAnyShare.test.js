import { describe, it, expect } from "vitest";
import { hasAnyShare } from "./hasAnyShare.js";

describe("hasAnyShare", () => {
    it("dit oui dès qu'un segment porte quelque chose", () => {
        expect(hasAnyShare([{ value: 0 }, { value: 3 }])).toBe(true);
    });

    // The defect: the dashboard counted the segments. A breakdown bar receives
    // one per known category, filled or not, so a site without a single comment
    // showed a titled card above an empty bar.
    it("dit non quand tous les segments sont à zéro", () => {
        expect(hasAnyShare([{ value: 0 }, { value: 0 }, { value: 0 }])).toBe(
            false,
        );
    });

    it("dit non sur une liste vide ou absente", () => {
        expect(hasAnyShare([])).toBe(false);
        expect(hasAnyShare(undefined)).toBe(false);
    });

    it("traite une valeur manquante comme zéro", () => {
        expect(hasAnyShare([{}, { value: null }])).toBe(false);
    });
});
