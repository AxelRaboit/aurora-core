import { describe, it, expect } from "vitest";
import {
    AAA_NORMAL_TEXT,
    bestContrastRatio,
    contrastRatio,
    meetsAaa,
    needsLightText,
} from "./surfaceContrast.js";

/**
 * The reference colours are deliberately the same as those of the PHP test
 * `SurfaceContrastTest`. If the two implementations ever diverge, this is
 * where it will show.
 */
describe("surfaceContrast", () => {
    it("gives the known WCAG maximum for black on white", () => {
        expect(contrastRatio("#000000", "#ffffff")).toBeCloseTo(21, 2);
    });

    it("gives no contrast for a colour against itself", () => {
        expect(contrastRatio("#3b82f6", "#3b82f6")).toBeCloseTo(1, 3);
    });

    it.each([
        ["#ffffff", false, "le fond historique garde son texte sombre"],
        ["#000000", true, "texte clair, évidemment"],
        ["#0f172a", true, "les fonds sombres saturés appellent du clair"],
        ["#fef9c3", false, "clair malgré la saturation"],
        ["#dc2626", true, "un seuil de luminance à 50 % se tromperait ici"],
        ["#10b981", false, "le vert est lumineux : texte sombre"],
    ])("picks the text colour for %s", (hex, expected) => {
        expect(needsLightText(hex)).toBe(expected);
    });

    it("flags a mid grey as failing AAA", () => {
        expect(meetsAaa("#808080")).toBe(false);
    });

    it("passes AAA at both extremes", () => {
        expect(meetsAaa("#ffffff")).toBe(true);
        expect(meetsAaa("#000000")).toBe(true);
    });

    it("never lets any background fall below AA", () => {
        // The invariant that justifies flagging AAA rather than AA, checked on the
        // 256 greys, where the worst case lies.
        let worst = 21;
        for (let grey = 0; grey <= 255; grey += 1) {
            const hex = `#${grey.toString(16).padStart(2, "0").repeat(3)}`;
            worst = Math.min(worst, bestContrastRatio(hex));
        }

        expect(worst).toBeGreaterThan(4.5);
        expect(worst).toBeLessThan(AAA_NORMAL_TEXT);
        expect(worst).toBeCloseTo(4.608, 2);
    });

    it.each(["", "#zzzzzz", "#12", "rouge", null, undefined])(
        "falls back to the light set for %s",
        (hex) => {
            expect(needsLightText(hex)).toBe(false);
        },
    );

    it("accepts short and bare notations", () => {
        expect(needsLightText("#000")).toBe(true);
        expect(needsLightText("000000")).toBe(true);
        expect(needsLightText("  #fff  ")).toBe(false);
    });
});
