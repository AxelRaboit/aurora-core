import { describe, it, expect } from "vitest";
import { excerptOfBlocks } from "./excerptOfBlocks.js";

describe("excerptOfBlocks", () => {
    it("reads the text of the blocks a note is made of", () => {
        expect(
            excerptOfBlocks([
                { type: "header", data: { text: "Brief" } },
                { type: "paragraph", data: { text: "Décaler la campagne." } },
            ]),
        ).toBe("Brief\nDécaler la campagne.");
    });

    it("strips the markup the editor puts inside a paragraph", () => {
        // Une carte qui montrerait « <b> » apprendrait au lecteur que la note
        // est cassée, ce qu'elle n'est pas.
        expect(
            excerptOfBlocks([
                {
                    type: "paragraph",
                    data: { text: "Logo <b>livré</b>&nbsp;jeudi" },
                },
            ]),
        ).toBe("Logo livré jeudi");
    });

    it("reads a list whichever shape its items have", () => {
        // L'outil a changé de forme entre deux versions, et les deux se lisent.
        expect(
            excerptOfBlocks([
                { type: "list", data: { items: ["Un", "Deux"] } },
            ]),
        ).toBe("Un\nDeux");

        expect(
            excerptOfBlocks([
                {
                    type: "list",
                    data: { items: [{ content: "Un" }, { content: "Deux" }] },
                },
            ]),
        ).toBe("Un\nDeux");
    });

    it("falls back on an image's caption, which is all it has to say", () => {
        expect(
            excerptOfBlocks([
                {
                    type: "image",
                    data: { file: { url: "/x" }, caption: "La façade" },
                },
            ]),
        ).toBe("La façade");
    });

    it("cuts a long note rather than filling the card with it", () => {
        const excerpt = excerptOfBlocks(
            [{ type: "paragraph", data: { text: "mot ".repeat(400) } }],
            40,
        );

        expect(excerpt.length).toBeLessThanOrEqual(41);
        expect(excerpt.endsWith("…")).toBe(true);
    });

    it("says nothing about a note with no body", () => {
        expect(excerptOfBlocks([])).toBe("");
        expect(excerptOfBlocks(null)).toBe("");
        expect(excerptOfBlocks(undefined)).toBe("");
    });
});
