import { describe, it, expect, vi } from "vitest";

// Le module s'arme au chargement et interroge `matchMedia`, absent de jsdom.
vi.stubGlobal("matchMedia", (query) => ({
    matches: false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
}));

const { readFigure, format, ease } = await import("./countUp.js");

describe("readFigure", () => {
    it("lit un entier simple", () => {
        expect(readFigure("24")).toMatchObject({
            value: 24,
            decimals: 0,
            suffix: "",
        });
    });

    it("garde l'unité et ne compte que le nombre", () => {
        expect(readFigure("3 langues")).toMatchObject({
            value: 3,
            suffix: " langues",
        });
        expect(readFigure("40 %")).toMatchObject({ value: 40, suffix: " %" });
    });

    it("relit le séparateur de milliers de la page plutôt que de le deviner", () => {
        // U+202F, ce que PHP écrit en français.
        const figure = readFigure("1 250");

        expect(figure.value).toBe(1250);
        expect(figure.group).toBe(" ");
        expect(format(1250, figure)).toBe("1 250");
    });

    it("relit la virgule décimale et le nombre de décimales", () => {
        const figure = readFigure("9,5");

        expect(figure.value).toBe(9.5);
        expect(figure.decimals).toBe(1);
        expect(format(9.5, figure)).toBe("9,5");
    });

    it("refuse ce qui ne commence pas par un nombre", () => {
        expect(readFigure("plus de 20")).toBeNull();
        expect(readFigure("∞")).toBeNull();
        expect(readFigure("")).toBeNull();
    });

    it("traite une plage comme un nombre suivi de texte, pas comme deux", () => {
        // On préfère compter jusqu'à 2 et garder « à 3 » que d'animer de
        // travers ou de refuser d'afficher.
        expect(readFigure("2 à 3")).toMatchObject({ value: 2, suffix: " à 3" });
    });
});

describe("format", () => {
    it("groupe les milliers comme la page le faisait", () => {
        const figure = readFigure("1 250 000");

        expect(format(1250000, figure)).toBe("1 250 000");
    });

    it("ne groupe rien quand la page ne groupait pas", () => {
        const figure = readFigure("1250");

        expect(format(1250, figure)).toBe("1250");
    });

    it("arrondit aux décimales de la valeur écrite", () => {
        const figure = readFigure("9,5");

        expect(format(9.47, figure)).toBe("9,5");
    });
});

describe("ease", () => {
    it("part de zéro et arrive à un", () => {
        expect(ease(0)).toBe(0);
        expect(ease(1)).toBe(1);
    });

    it("freine sur la fin, pour que le nombre se pose", () => {
        expect(ease(0.5)).toBeGreaterThan(0.5);
    });

    it("borne ce qui sort de l'intervalle", () => {
        expect(ease(-1)).toBe(0);
        expect(ease(4)).toBe(1);
    });
});
