import { describe, expect, it } from "vitest";
import {
    threeWayMerge,
    threeWayTags,
    threeWayValue,
} from "./noteThreeWayMerge.js";

const BASE = [
    "# Compte rendu",
    "",
    "Point un.",
    "Point deux.",
    "Point trois.",
].join("\n");

describe("la fusion à trois branches", () => {
    it("met ensemble deux modifications à des endroits différents", () => {
        // She rewrote the first point, he rewrote the last one.
        const mine = BASE.replace("Point un.", "Point un, revu.");
        const theirs = BASE.replace("Point trois.", "Point trois, revu.");

        expect(threeWayMerge(BASE, mine, theirs)).toBe(
            [
                "# Compte rendu",
                "",
                "Point un, revu.",
                "Point deux.",
                "Point trois, revu.",
            ].join("\n"),
        );
    });

    it("garde les deux ajouts quand chacun écrit à un bout", () => {
        const mine = `${BASE}\n\nAjouté en bas.`;
        const theirs = ["Avant tout.", "", ...BASE.split("\n")].join("\n");

        expect(threeWayMerge(BASE, mine, theirs)).toBe(
            [
                "Avant tout.",
                "",
                "# Compte rendu",
                "",
                "Point un.",
                "Point deux.",
                "Point trois.",
                "",
                "Ajouté en bas.",
            ].join("\n"),
        );
    });

    it("refuse quand les deux réécrivent la même ligne", () => {
        const mine = BASE.replace("Point deux.", "Point deux, par elle.");
        const theirs = BASE.replace("Point deux.", "Point deux, par lui.");

        expect(threeWayMerge(BASE, mine, theirs)).toBeNull();
    });

    it("refuse quand l'un supprime la ligne que l'autre réécrit", () => {
        const mine = BASE.replace("Point deux.\n", "");
        const theirs = BASE.replace("Point deux.", "Point deux, précisé.");

        expect(threeWayMerge(BASE, mine, theirs)).toBeNull();
    });

    it("refuse deux insertions au même endroit, faute de savoir laquelle passe devant", () => {
        const mine = BASE.replace(
            "Point deux.",
            "Inséré par elle.\nPoint deux.",
        );
        const theirs = BASE.replace(
            "Point deux.",
            "Inséré par lui.\nPoint deux.",
        );

        expect(threeWayMerge(BASE, mine, theirs)).toBeNull();
    });

    it("accepte la même modification tapée par les deux, sans la dupliquer", () => {
        const same = BASE.replace("Point deux.", "Point deux, revu.");

        expect(threeWayMerge(BASE, same, same)).toBe(same);
    });

    it("rend l'autre version quand on n'a rien touché", () => {
        const theirs = BASE.replace("Point un.", "Point un, revu.");

        expect(threeWayMerge(BASE, BASE, theirs)).toBe(theirs);
    });

    it("garde la nôtre quand c'est l'autre qui n'a rien touché", () => {
        const mine = BASE.replace("Point un.", "Point un, revu.");

        expect(threeWayMerge(BASE, mine, BASE)).toBe(mine);
    });

    it("part d'un texte vide sans se tromper", () => {
        expect(threeWayMerge("", "Écrit par elle.", "")).toBe(
            "Écrit par elle.",
        );
        expect(threeWayMerge("", "", "Écrit par lui.")).toBe("Écrit par lui.");
        expect(threeWayMerge("", "Par elle.", "Par lui.")).toBeNull();
    });

    it("tient une suppression de chaque côté à des endroits différents", () => {
        const mine = BASE.split("\n")
            .filter((line) => "Point un." !== line)
            .join("\n");
        const theirs = BASE.split("\n")
            .filter((line) => "Point trois." !== line)
            .join("\n");

        expect(threeWayMerge(BASE, mine, theirs)).toBe(
            ["# Compte rendu", "", "Point deux."].join("\n"),
        );
    });

    /**
     * A title is one line, so the same function answers: exactly one of us
     * renamed it, or neither, or the person decides.
     */
    it("sert aussi pour un titre, qui est une ligne", () => {
        expect(
            threeWayMerge("Compte rendu", "Compte rendu du 8", "Compte rendu"),
        ).toBe("Compte rendu du 8");
        expect(
            threeWayMerge("Compte rendu", "Compte rendu", "Réunion du 8"),
        ).toBe("Réunion du 8");
        expect(
            threeWayMerge("Compte rendu", "Compte rendu du 8", "Réunion du 8"),
        ).toBeNull();
    });
});

describe("une valeur unique à trois branches", () => {
    it("prend celle qui a changé, et refuse quand les deux ont changé", () => {
        expect(threeWayValue("plain", "paper", "plain")).toEqual({
            value: "paper",
        });
        expect(threeWayValue("plain", "plain", "ink")).toEqual({
            value: "ink",
        });
        expect(threeWayValue("plain", "paper", "ink")).toBeNull();
    });

    it("emballe la valeur, pour qu'un null légitime ne se lise pas comme un refus", () => {
        expect(threeWayValue("une adresse", null, "une adresse")).toEqual({
            value: null,
        });
    });
});

describe("les étiquettes, qui sont un ensemble", () => {
    it("gardent ce que chacun a ajouté", () => {
        expect(
            threeWayTags(
                ["projet"],
                ["projet", "urgent"],
                ["projet", "client"],
            ),
        ).toEqual(["projet", "urgent", "client"]);
    });

    it("retirent ce que l'un des deux a retiré", () => {
        expect(
            threeWayTags(
                ["projet", "brouillon"],
                ["projet"],
                ["projet", "brouillon"],
            ),
        ).toEqual(["projet"]);
    });

    it("n'inventent pas de conflit : deux ajouts font deux étiquettes", () => {
        expect(threeWayTags([], ["urgent"], ["client"])).toEqual([
            "urgent",
            "client",
        ]);
    });

    it("ne dupliquent pas une étiquette ajoutée par les deux", () => {
        expect(threeWayTags([], ["urgent"], ["urgent"])).toEqual(["urgent"]);
    });
});
