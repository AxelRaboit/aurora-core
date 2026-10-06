import { describe, expect, it } from "vitest";
import { stylePayload } from "./useDeckAppearance.js";

/**
 * What the appearance panel sends to the server.
 *
 * This file exists because of one specific failure: seven settings had been
 * added to the style, the panel, the preview and the looks, and the function
 * that built the request kept a hand-written list of keys that did not name
 * them. Nothing failed. The deck saved, the page announced a success, and
 * none of the seven reached the database. The last test in this file is the
 * one that would have screamed.
 */
const SHAPE = {
    background: null,
    ink: null,
    accent: null,
    gradient: null,
    pattern: null,
    margins: "normal",
    hairline: false,
    rules: false,
    titleCase: "normal",
    bullets: "disc",
    fontPair: null,
    logoMediaId: null,
    logoPlacement: "none",
    footerText: "",
    slideNumbers: false,
    transition: null,
};

describe("stylePayload", () => {
    it("n'envoie rien pour un style auquel personne n'a touche", () => {
        expect(stylePayload({ ...SHAPE }, SHAPE)).toEqual({
            margins: "normal",
            titleCase: "normal",
            bullets: "disc",
        });
    });

    it("porte les reglages que le panneau a ecrits", () => {
        const style = {
            ...SHAPE,
            gradient: "halo",
            pattern: "grid",
            margins: "wide",
            rules: true,
            bullets: "arrow",
        };

        expect(stylePayload(style, SHAPE)).toMatchObject({
            gradient: "halo",
            pattern: "grid",
            margins: "wide",
            rules: true,
            bullets: "arrow",
        });
    });

    it("laisse le theme decider quand rien n'a ete choisi", () => {
        const sent = stylePayload({ ...SHAPE }, SHAPE);

        expect(sent.gradient).toBeUndefined();
        expect(sent.pattern).toBeUndefined();
        expect(sent.background).toBeUndefined();
    });

    it("distingue le fond a plat de l'absence de choix", () => {
        expect(
            stylePayload({ ...SHAPE, gradient: "none" }, SHAPE).gradient,
        ).toBe("none");
    });

    it("ne garde pas un interrupteur eteint ni un texte vide", () => {
        const sent = stylePayload(
            { ...SHAPE, hairline: false, footerText: "   " },
            SHAPE,
        );

        expect(sent.hairline).toBeUndefined();
        expect(sent.footerText).toBeUndefined();
    });

    it("range le placement avec le logo qu'il place", () => {
        expect(
            stylePayload({ ...SHAPE, logoPlacement: "every" }, SHAPE)
                .logoPlacement,
        ).toBeUndefined();
        expect(
            stylePayload(
                { ...SHAPE, logoMediaId: 4, logoPlacement: "every" },
                SHAPE,
            ),
        ).toMatchObject({
            logoMediaId: 4,
            logoPlacement: "every",
        });
    });

    /**
     * The test that closes the failure. Every key of the style's shape must
     * be able to reach the server: if an eighth is added tomorrow and someone
     * goes back to a hand-written list, this is where it breaks.
     */
    it("laisse passer chaque cle de la forme du style", () => {
        const filled = {};

        for (const [key, blank] of Object.entries(SHAPE)) {
            filled[key] =
                typeof blank === "boolean"
                    ? true
                    : key === "logoMediaId"
                      ? 7
                      : "x";
        }

        const sent = stylePayload(filled, SHAPE);

        expect(Object.keys(sent).sort()).toEqual(Object.keys(SHAPE).sort());
    });
});
