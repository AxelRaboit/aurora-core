import { describe, expect, it } from "vitest";
import { frenchElision } from "./frenchElision.js";

describe("frenchElision", () => {
    it("elides de before a name that starts with a vowel", () => {
        expect(frenchElision("Livrables de Atelier Dupont")).toBe(
            "Livrables d'Atelier Dupont",
        );
        expect(frenchElision("Retirer le rôle Dev de Émilie ?")).toBe(
            "Retirer le rôle Dev d'Émilie ?",
        );
        expect(frenchElision("Copie de idées")).toBe("Copie d'idées");
    });

    it("keeps de before a consonant, y, h or a single letter", () => {
        expect(frenchElision("Équipe de Martin")).toBe("Équipe de Martin");
        expect(frenchElision("Demande de Yann")).toBe("Demande de Yann");
        expect(frenchElision("Contrat de Hélène")).toBe("Contrat de Hélène");
        expect(frenchElision("Index de A à Z")).toBe("Index de A à Z");
    });

    it("leaves words that only end in de alone", () => {
        expect(frenchElision("Monde entier")).toBe("Monde entier");
        expect(frenchElision("Mode avancé")).toBe("Mode avancé");
    });

    it("elides a capital De at the start", () => {
        expect(frenchElision("De Anne")).toBe("D'Anne");
    });

    it("passes anything that is not a string through", () => {
        expect(frenchElision(null)).toBe(null);
    });
});
