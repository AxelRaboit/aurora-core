import { describe, it, expect } from "vitest";
import { markQuotes } from "./noteCommentMarks.js";
import { mentionMarkup } from "./markedExtensions/markedMentions.js";
import { useMarkdownRenderer } from "./useMarkdownRenderer.js";

function rootOf(html) {
    const root = document.createElement("div");
    root.innerHTML = html;

    return root;
}

describe("markQuotes", () => {
    it("marks the first occurrence of each passage and says which were found", () => {
        const root = rootOf(
            "<p>Le budget reste à valider. Le budget encore.</p><pre><code>Le budget</code></pre>",
        );
        const found = markQuotes(root, [
            { id: 1, quote: "Le budget" },
            { id: 2, quote: "absent" },
        ]);

        expect([...found]).toEqual([1]);
        const marks = root.querySelectorAll("mark.md-comment-mark");
        expect(marks).toHaveLength(1);
        expect(marks[0].dataset.commentId).toBe("1");
        expect(marks[0].closest("pre")).toBeNull();
    });

    it("leaves no old mark behind when the threads change", () => {
        const root = rootOf("<p>Le budget reste à valider.</p>");
        markQuotes(root, [{ id: 1, quote: "budget" }]);
        markQuotes(root, []);

        expect(root.querySelectorAll("mark")).toHaveLength(0);
        expect(root.textContent).toBe("Le budget reste à valider.");
    });
});

describe("mentions", () => {
    it("writes a person picked in the menu, and renders it as a chip", () => {
        const markup = mentionMarkup({ id: 12, name: "Marie [Dupont]" });
        expect(markup).toBe(
            "@[Marie  Dupont ](user:12)".replace("  ", " ").replace(" ]", "]"),
        );

        const { render } = useMarkdownRenderer();
        const html = render("Voir avec @[Marie Dupont](user:12) demain.");
        expect(html).toContain(
            '<span class="md-mention" data-user-id="12">@Marie Dupont</span>',
        );
    });
});
