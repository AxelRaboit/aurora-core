import { describe, it, expect, vi } from "vitest";

// Le module s'arme au chargement et appelle `matchMedia`, que jsdom ne
// fournit pas. Le double doit donc être en place avant l'import.
vi.stubGlobal("matchMedia", (query) => ({
    matches: false,
    media: query,
    addEventListener: () => {},
    removeEventListener: () => {},
}));

const { cascadeOrder, cascadeDelay } = await import("./scrollReveal.js");

/**
 * Une entrée d'observateur, réduite à ce que la cascade lit.
 *
 * `getBoundingClientRect` plutôt qu'un champ : c'est ce que le vrai code
 * appelle, et jsdom renvoie des zéros pour tout le monde - un test qui
 * s'appuierait sur la mise en page réelle ne vérifierait rien.
 */
function entry(top, left, isIntersecting = true) {
    return {
        isIntersecting,
        target: { getBoundingClientRect: () => ({ top, left }) },
    };
}

describe("cascadeOrder", () => {
    it("ne garde que ce qui entre dans la vue", () => {
        const dedans = entry(100, 0);
        const dehors = entry(50, 0, false);

        expect(cascadeOrder([dehors, dedans])).toEqual([dedans.target]);
    });

    it("joue de haut en bas, quel que soit l'ordre reçu", () => {
        const haut = entry(10, 0);
        const milieu = entry(200, 0);
        const bas = entry(400, 0);

        expect(cascadeOrder([bas, haut, milieu])).toEqual([
            haut.target,
            milieu.target,
            bas.target,
        ]);
    });

    it("départage de gauche à droite à hauteur égale", () => {
        const gauche = entry(100, 0);
        const centre = entry(100, 300);
        const droite = entry(100, 600);

        expect(cascadeOrder([droite, centre, gauche])).toEqual([
            gauche.target,
            centre.target,
            droite.target,
        ]);
    });

    it("rend un tableau vide quand rien n'arrive", () => {
        expect(cascadeOrder([entry(0, 0, false)])).toEqual([]);
    });
});

describe("cascadeDelay", () => {
    it("ne fait pas attendre la première", () => {
        expect(cascadeDelay(0)).toBe(0);
    });

    it("décale chaque suivante d'un cran", () => {
        expect(cascadeDelay(1)).toBe(70);
        expect(cascadeDelay(3)).toBe(210);
    });

    it("plafonne, parce qu'au-delà on attend la fin au lieu de lire une cascade", () => {
        expect(cascadeDelay(8)).toBe(560);
        expect(cascadeDelay(40)).toBe(560);
    });

    it("traite un rang négatif comme le premier plutôt que de remonter le temps", () => {
        expect(cascadeDelay(-3)).toBe(0);
    });
});
