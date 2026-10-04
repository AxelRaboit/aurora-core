import { describe, expect, it } from "vitest";
import { countInText, countPlaceholders } from "./placeholders.js";

describe("placeholders", () => {
    it("counts each bracketed blank once, markup or not", () => {
        expect(countInText("[Nom de la marque] · [Mois <b>année</b>]")).toBe(2);
    });

    it("leaves alone what is not a blank", () => {
        expect(countInText("Un tableau [] vide, et [une\nligne coupée]")).toBe(
            0,
        );
    });

    it("walks a whole grid's content", () => {
        const content = {
            zones: {
                a: {
                    blocks: [{ type: "paragraph", data: { text: "[Ville]" } }],
                },
                b: {
                    items: { x: { title: "[0 %]", description: "fini" } },
                    label: "[Titre]",
                },
            },
        };

        expect(countPlaceholders(content)).toBe(3);
    });
});
