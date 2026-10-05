import { describe, expect, it } from "vitest";
import { wordingDiff, wordingLines } from "./wordingDiff.js";

const trame = {
    title: "CONTRAT",
    blocks: [
        { type: "header", data: { text: "Article 1", level: 2 } },
        {
            type: "paragraph",
            data: { text: "Le forfait est de <b>{{contract.amount}}</b>." },
        },
        {
            type: "list",
            data: {
                items: [
                    { content: "Un rendez-vous par mois", items: [] },
                    "Un rapport",
                ],
            },
        },
    ],
};

describe("wordingDiff", () => {
    it("finds nothing between a wording and itself", () => {
        expect(wordingDiff(trame, structuredClone(trame))).toEqual({
            added: 0,
            removed: 0,
            changes: [],
        });
    });

    it("names an added clause and a struck one, as text", () => {
        const adapted = structuredClone(trame);
        adapted.blocks.splice(2, 0, {
            type: "paragraph",
            data: { text: "Livraison le samedi." },
        });
        adapted.blocks[3].data.items.pop();

        expect(wordingDiff(trame, adapted)).toEqual({
            added: 1,
            removed: 1,
            changes: [
                { type: "added", text: "Livraison le samedi." },
                { type: "removed", text: "• Un rapport" },
            ],
        });
    });

    it("shows a block edited in place as the old line struck and the new one added", () => {
        const adapted = structuredClone(trame);
        adapted.blocks[1].data.text =
            "Le forfait est de {{contract.amount}}, payable d'avance.";

        const { changes } = wordingDiff(trame, adapted);

        expect(changes.map((change) => change.type)).toEqual([
            "removed",
            "added",
        ]);
        expect(changes[0].text).toBe("Le forfait est de {{contract.amount}}.");
    });

    it("reads a table one row per line and ignores markup", () => {
        expect(
            wordingLines("T", [
                { type: "table", data: { content: [["<i>A</i>", "B"]] } },
            ]),
        ).toEqual(["T", "A | B"]);
    });
});
