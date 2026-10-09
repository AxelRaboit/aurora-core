import { describe, it, expect } from "vitest";
import { splitSlides } from "./noteSlides.js";
import { docxBlocks, plainSyntax } from "./noteDocx.js";

describe("splitSlides", () => {
    it("cuts on `---` lines when the note has them", () => {
        expect(
            splitSlides("# Un\nTexte\n---\n## Deux\n---\n\n---\nTrois"),
        ).toEqual(["# Un\nTexte", "## Deux", "Trois"]);
    });

    it("cuts on headings otherwise, and never inside code", () => {
        const note =
            "Intro\n# Un\nA\n## Deux\n```\n# pas un titre\n---\n```\n### Trois reste";
        expect(splitSlides(note)).toEqual([
            "Intro",
            "# Un\nA",
            "## Deux\n```\n# pas un titre\n---\n```\n### Trois reste",
        ]);
    });
});

describe("docxBlocks", () => {
    it("reads headings, styled words, lists, tasks, quotes, code and tables", () => {
        const blocks = docxBlocks(
            "## Titre\n\nDu **gras** et du *penché*.\n\n- [x] Fait\n- Un\n  1. Sous\n\n> Citation\n\n```js\nlet a;\n```\n\n| A | B |\n| - | - |\n| 1 | 2 |",
        );

        expect(blocks.map((block) => block.type)).toEqual([
            "heading",
            "paragraph",
            "item",
            "item",
            "item",
            "quote",
            "code",
            "table",
        ]);
        expect(blocks[1].runs.find((run) => run.bold)?.text).toBe("gras");
        expect(blocks[2].runs[0].text).toBe("☑ ");
        expect(blocks[4]).toMatchObject({ ordered: true, level: 1 });
        expect(blocks[7].rows[0][1][0].text).toBe("2");
    });

    it("writes the note's own syntax as words", () => {
        expect(
            plainSyntax(
                "Voir [[Tarifs|les tarifs]], ==ceci== et @[Marie](user:3) ^bloc1",
            ),
        ).toBe("Voir les tarifs, ceci et @Marie");
    });
});
