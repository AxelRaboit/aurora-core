import { describe, it, expect } from "vitest";
import { useMarkdownRenderer } from "./useMarkdownRenderer.js";
import { toggleCheckboxInContent } from "./markedExtensions/markedCheckboxes.js";

describe("useMarkdownRenderer", () => {
    const { render } = useMarkdownRenderer();

    it("renders a basic markdown paragraph", () => {
        const html = render("Hello **world**");
        expect(html).toContain("<strong>world</strong>");
    });

    it("renders a wiki-link with data-note-title attribute", () => {
        const html = render("See [[My Page]] for details.");
        expect(html).toContain('class="wiki-link"');
        expect(html).toContain('data-note-title="My Page"');
        expect(html).toContain(">My Page<");
    });

    it("renders a wiki-link with heading anchor", () => {
        const html = render("Jump to [[Guide#Setup]].");
        expect(html).toContain('data-note-title="Guide"');
        expect(html).toContain('data-heading="Setup"');
        expect(html).toContain("Guide &gt; Setup");
    });

    it("renders a callout block", () => {
        const html = render("> [!info] Title\n> body line");
        expect(html).toContain("callout callout-info");
        expect(html).toContain('callout-title">Title<');
        expect(html).toContain("body line");
    });

    it("falls back to a default label when callout has no title", () => {
        const html = render("> [!warning]\n> watch out");
        expect(html).toContain('callout-title">Warning<');
    });

    it("renders interactive task list checkboxes with sequential indices", () => {
        const html = render("- [ ] first\n- [x] done\n- [ ] last");
        expect(html).toContain('data-checkbox-index="0"');
        expect(html).toContain('data-checkbox-index="1"');
        expect(html).toContain('data-checkbox-index="2"');
        // checked attribute on the second one
        const match = html.match(/data-checkbox-index="1"[^>]*checked/);
        expect(match).not.toBeNull();
    });

    it("sanitizes embedded script tags", () => {
        const html = render('Hello <script>alert("xss")</script>');
        expect(html).not.toContain("<script");
    });

    it("returns empty string for empty input", () => {
        expect(render("")).toBe("");
        expect(render(null)).toBe("");
    });

    it("highlights a fenced code block with a known language", () => {
        const html = render("```js\nconst x = 1;\n```");
        expect(html).toContain('class="code-block"');
        expect(html).toContain('class="code-block-lang">js<');
        expect(html).toContain('class="hljs language-js"');
        // hljs always emits at least one token span for valid input
        expect(html).toMatch(/<span class="hljs-/);
    });

    it("converts a single newline to <br> (soft breaks enabled)", () => {
        const html = render("line one\nline two");
        // marked produces a <br> for the soft break - assert on the tag
        // rather than exact formatting so we survive whitespace tweaks.
        expect(html).toMatch(/line one\s*<br\s*\/?>\s*line two/);
    });

    it("falls back to escaped plain text for an unknown language", () => {
        const html = render("```\n<not-a-tag>\n```");
        expect(html).toContain('class="code-block"');
        expect(html).toContain("&lt;not-a-tag&gt;");
        // no language label for unfenced langs
        expect(html).not.toContain("code-block-lang");
    });
});

describe("toggleCheckboxInContent", () => {
    it("flips an unchecked box to checked", () => {
        const out = toggleCheckboxInContent("- [ ] task", 0);
        expect(out).toBe("- [x] task");
    });

    it("flips a checked box to unchecked", () => {
        const out = toggleCheckboxInContent("- [x] task", 0);
        expect(out).toBe("- [ ] task");
    });

    it("only toggles the matching index", () => {
        const src = "- [ ] a\n- [ ] b\n- [x] c";
        const out = toggleCheckboxInContent(src, 1);
        expect(out).toBe("- [ ] a\n- [x] b\n- [x] c");
    });

    it("handles indented checkboxes", () => {
        const out = toggleCheckboxInContent("  - [ ] nested", 0);
        expect(out).toBe("  - [x] nested");
    });

    it("handles `*` and `+` bullets", () => {
        const out = toggleCheckboxInContent("* [ ] star\n+ [ ] plus", 1);
        expect(out).toBe("* [ ] star\n+ [x] plus");
    });
});
