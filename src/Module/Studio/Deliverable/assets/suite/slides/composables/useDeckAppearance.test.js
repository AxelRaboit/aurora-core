import { describe, expect, it } from "vitest";
import { stylePayload } from "./useDeckAppearance.js";

/**
 * Ce que le panneau d'apparence envoie au serveur.
 *
 * Ce fichier existe a cause d'une panne precise : sept reglages avaient ete
 * ajoutes au style, au panneau, a l'apercu et aux looks, et la fonction qui
 * composait l'envoi tenait une liste de cles ecrite a la main qui ne les
 * nommait pas. Rien n'echouait. Le deck s'enregistrait, la page annoncait un
 * succes, et aucun des sept ne touchait la base. Le dernier test de ce fichier
 * est celui qui aurait crie.
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
     * Le test qui ferme la panne. Toute cle de la forme du style doit pouvoir
     * atteindre le serveur : si une huitieme est ajoutee demain et que
     * quelqu'un revient a une liste ecrite a la main, c'est ici que ca casse.
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
