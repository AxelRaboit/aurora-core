import { describe, expect, it } from "vitest";
import { cells, decorated, headed, measured } from "./cells.js";

describe("cells", () => {
    it("splits a line on the pipe and trims each cell", () => {
        expect(cells("Accueil | 2,4 s | 0,8 s")).toEqual([
            "Accueil",
            "2,4 s",
            "0,8 s",
        ]);
    });

    /** Position is meaning in a table: dropping a blank shifts a column. */
    it("keeps an empty cell rather than closing the gap", () => {
        expect(cells("Avant | | Après")).toEqual(["Avant", "", "Après"]);
    });

    it("treats a line with no pipe as one cell", () => {
        expect(cells("Cadrage")).toEqual(["Cadrage"]);
    });

    it("answers an empty list of one empty cell for nothing at all", () => {
        expect(cells(null)).toEqual([""]);
    });
});

describe("headed", () => {
    it("takes the first cell as the heading and the rest as the body", () => {
        expect(headed("Cache | Listes et fiches produit")).toEqual({
            head: "Cache",
            body: "Listes et fiches produit",
        });
    });

    /** A card whose body itself holds a pipe keeps it, rather than losing half. */
    it("puts the separator back when the body carried one", () => {
        expect(headed("Avant | 2,4 s | 0,8 s").body).toBe("2,4 s | 0,8 s");
    });

    it("leaves the body empty when the line is only a heading", () => {
        expect(headed("Index")).toEqual({ head: "Index", body: "" });
    });
});

describe("decorated", () => {
    it("reads the badge and the icon from the third and fourth cells", () => {
        expect(decorated("Cadrage | deux semaines | Phase 1 | clock")).toEqual({
            head: "Cadrage",
            body: "deux semaines",
            badge: "Phase 1",
            icon: "clock",
        });
    });

    it("leaves a two-cell line meaning exactly what it always meant", () => {
        expect(decorated("Cadrage | deux semaines")).toEqual({
            head: "Cadrage",
            body: "deux semaines",
            badge: "",
            icon: "",
        });
    });

    it("keeps an empty cell, so a badge can come without a description", () => {
        expect(decorated("Cadrage | | Phase 1")).toEqual({
            head: "Cadrage",
            body: "",
            badge: "Phase 1",
            icon: "",
        });
    });
});

describe("measured", () => {
    it("clamps the share into the range a bar can draw", () => {
        expect(measured("72% | des dossiers | 72").share).toBe(72);
        expect(measured("x | y | 140").share).toBe(100);
        expect(measured("x | y | -3").share).toBe(0);
    });

    it("draws no gauge rather than an empty one when the share is not a number", () => {
        expect(measured("4 | outils remplacés").share).toBeNull();
        expect(measured("4 | outils | beaucoup").share).toBeNull();
    });
});
