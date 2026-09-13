import { describe, expect, it } from "vitest";
import { cells, headed } from "./cells.js";

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
