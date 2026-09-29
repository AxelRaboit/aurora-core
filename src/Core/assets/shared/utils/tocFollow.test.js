import { describe, it, expect } from "vitest";
import { currentIndex, progress, numberOf } from "./tocFollow.js";

describe("currentIndex", () => {
    it("ne désigne rien au-dessus du premier titre", () => {
        expect(currentIndex([300, 900], 120)).toBe(-1);
    });

    it("désigne le dernier titre passé sous la ligne de lecture", () => {
        expect(currentIndex([-800, -200, 100, 700], 120)).toBe(2);
    });
});

describe("progress", () => {
    it("va de 0 à 1 et ne déborde pas", () => {
        expect(progress(0, 1000)).toBe(0);
        expect(progress(500, 1000)).toBe(0.5);
        expect(progress(1200, 1000)).toBe(1);
    });

    it("tient une page qui ne défile pas pour lue", () => {
        expect(progress(0, 0)).toBe(1);
    });
});

describe("numberOf", () => {
    const levels = [2, 2, 2, 3, 3, 2];

    it("numérote les titres comme l'index", () => {
        expect(numberOf(levels, 0)).toBe("01");
        expect(numberOf(levels, 2)).toBe("03");
        expect(numberOf(levels, 4)).toBe("03.2");
        expect(numberOf(levels, 5)).toBe("04");
    });

    it("rattache un sous-titre sans titre au-dessus au premier", () => {
        expect(numberOf([3], 0)).toBe("01.1");
    });
});
