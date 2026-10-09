import { describe, it, expect } from "vitest";
import { useMarkdownRenderer } from "./useMarkdownRenderer.js";
import { markColor } from "./markedExtensions/markedMarks.js";
import { parseWikiTarget } from "./markedExtensions/markedWikiLinks.js";

/** What a note can write since 09/10/2026, rendered. */
describe("useMarkdownRenderer, the richer Markdown", () => {
    const { render } = useMarkdownRenderer();

    it("highlights, in yellow or in the colour named", () => {
        expect(render("Un ==mot== surligné")).toContain(
            '<mark class="md-mark md-mark-yellow">mot</mark>',
        );
        expect(render("=={vert}vert==")).toContain("md-mark-green");
        expect(render("=={green}green==")).toContain("md-mark-green");
        // An unknown colour leaves the text as it was typed.
        expect(render("=={turquoise}x==")).not.toContain("<mark");
    });

    it("colours letters with {colour}text{/}", () => {
        expect(render("Un {rouge}mot{/} rouge")).toContain(
            '<span class="md-color md-color-red">mot</span>',
        );
    });

    it("folds a callout written with - or +, and the plain toggle", () => {
        const folded = render("> [!info]- Caché\n> dedans");
        expect(folded).toContain("<details");
        expect(folded).not.toContain(" open");
        expect(render("> [!tip]+ Ouvert\n> dedans")).toMatch(
            /<details[^>]* open/,
        );
        expect(render("> [!toggle] Détails\n> plus")).toContain(
            "callout-toggle",
        );
        // A callout without a sign does not fold, as before.
        expect(render("> [!info] Titre\n> corps")).not.toContain("<details");
    });

    it("renders a formula, and leaves prices alone", () => {
        expect(render("Soit $E=mc^2$.")).toContain('data-math="E=mc^2"');
        expect(render("Entre $5 et $10.")).not.toContain("md-math");
        expect(render("$$\nx^2\n$$")).toContain('data-display="1"');
    });

    it("leaves a diagram as its source until it is drawn", () => {
        const html = render("```mermaid\ngraph TD; A-->B\n```");
        expect(html).toContain('class="md-mermaid"');
        expect(html).toContain("A--&gt;B");
    });

    it("links with a shown text, to a paragraph, and includes a note", () => {
        expect(render("Voir [[Cabinet Verrier|le client]].")).toContain(
            'data-note-title="Cabinet Verrier"',
        );
        expect(render("Voir [[Cabinet Verrier|le client]].")).toContain(
            ">le client</a>",
        );
        expect(render("[[Note#^abc]]")).toContain('data-heading="^abc"');
        const embed = render("![[Cabinet Verrier#Le forfait]]");
        expect(embed).toContain('class="md-embed"');
        expect(embed).toContain('data-embed-title="Cabinet Verrier"');
        expect(embed).toContain('data-heading="Le forfait"');
    });

    it("names a paragraph with a trailing ^id, which leaves the text", () => {
        const html = render("Un paragraphe ^abc-1");
        expect(html).toContain('data-block-id="abc-1"');
        expect(html).not.toContain("^abc-1");
    });

    it("leaves a place for the table of contents", () => {
        expect(render("[[toc]]")).toContain('data-toc="1"');
        expect(render("[TOC]")).toContain('data-toc="1"');
    });

    it("renders footnotes", () => {
        const html = render("Une note[^1].\n\n[^1]: Le détail.");
        expect(html).toContain('href="#note-fn-1"');
        expect(html).toContain("Le détail.");
    });
});

describe("markColor", () => {
    it("reads French and English names", () => {
        expect(markColor("Rouge")).toBe("red");
        expect(markColor("grey")).toBe("gray");
        expect(markColor("magenta")).toBeNull();
    });
});

describe("parseWikiTarget", () => {
    it("reads the bar before the hash", () => {
        expect(parseWikiTarget("Note#Part|ici")).toEqual({
            noteTitle: "Note",
            heading: "Part",
            alias: "ici",
        });
        expect(parseWikiTarget(" Note ")).toEqual({
            noteTitle: "Note",
            heading: "",
            alias: "",
        });
    });
});
