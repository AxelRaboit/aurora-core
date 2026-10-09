import { describe, it, expect, vi } from "vitest";

vi.mock("./noteEmoji.js", () => ({
    loadEmojiData: () =>
        Promise.resolve({
            byShortcode: new Map([
                ["fusee", "🚀"],
                ["rocket", "🚀"],
            ]),
        }),
}));

import { enhanceNoteHtml, markdownSection } from "./noteHtmlEnhancer.js";
import { useMarkdownRenderer } from "./useMarkdownRenderer.js";

const { render } = useMarkdownRenderer();

function mountHtml(html) {
    const root = document.createElement("div");
    root.innerHTML = html;
    document.body.append(root);

    return root;
}

describe("enhanceNoteHtml", () => {
    it("adds a copy button to each code block", async () => {
        const root = mountHtml(render("```js\nconst a = 1;\n```"));
        await enhanceNoteHtml(root, { labels: { copy: "Copier" } });

        const button = root.querySelector("[data-copy-code]");
        expect(button).not.toBeNull();
        expect(button.textContent).toBe("Copier");
    });

    it("fills the table of contents with the note's headings, not the footnotes' label", async () => {
        const root = mountHtml(
            render("[[toc]]\n\n## Un\n\n### Deux\n\nTexte[^1].\n\n[^1]: Note."),
        );
        await enhanceNoteHtml(root, {
            labels: { tableOfContents: "Sommaire" },
        });

        const links = [...root.querySelectorAll(".md-toc-link")].map(
            (link) => link.textContent,
        );
        expect(links).toEqual(["Un", "Deux"]);
        expect(root.querySelector(".md-toc-title").textContent).toBe(
            "Sommaire",
        );
    });

    it("writes :name: as the emoji, but not inside code", async () => {
        const root = mountHtml(render("Décollage :fusee: et `:rocket:`"));
        await enhanceNoteHtml(root);

        expect(root.textContent).toContain("Décollage 🚀");
        expect(root.querySelector("code").textContent).toBe(":rocket:");
    });

    it("includes a note through the page's loader", async () => {
        const root = mountHtml(render("![[Fiche#Contexte]]"));
        const loadEmbed = vi.fn().mockResolvedValue("<p>Le contexte.</p>");
        await enhanceNoteHtml(root, { loadEmbed });

        expect(loadEmbed).toHaveBeenCalledWith({
            title: "Fiche",
            heading: "Contexte",
        });
        expect(root.querySelector(".md-embed-body").textContent).toBe(
            "Le contexte.",
        );
    });

    it("leaves an inclusion as a link when the page cannot fetch it", async () => {
        const root = mountHtml(render("![[Ailleurs]]"));
        await enhanceNoteHtml(root, { loadEmbed: () => Promise.resolve(null) });

        expect(root.querySelector(".md-embed-body")).toBeNull();
        expect(root.querySelector(".md-embed-title").textContent).toBe(
            "Ailleurs",
        );
    });
});

describe("markdownSection", () => {
    const note =
        "# Fiche\n\nIntro\n\n## Contexte\n\nLe contexte.\n\n### Détail\n\nPlus.\n\n## Suite\n\nAutre.\nUne ligne nommée ^ici";

    it("gives the whole note without a heading", () => {
        expect(markdownSection(note)).toBe(note);
    });

    it("gives a section down to the next heading of its level", () => {
        expect(markdownSection(note, "contexte")).toBe(
            "## Contexte\n\nLe contexte.\n\n### Détail\n\nPlus.\n",
        );
    });

    it("gives the paragraph named by ^id", () => {
        expect(markdownSection(note, "^ici")).toBe("Une ligne nommée ^ici");
    });

    it("gives nothing for a heading that does not exist", () => {
        expect(markdownSection(note, "Absent")).toBe("");
    });
});
