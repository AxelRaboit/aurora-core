import { describe, it, expect } from "vitest";
import { Marked } from "marked";
import {
    createImageDimensionsRenderer,
    updateImageDimensionInContent,
} from "./markedImageDimensions.js";

function render(markdown) {
    const marked = new Marked({ gfm: true });
    marked.use({ renderer: createImageDimensionsRenderer() });
    return marked.parse(markdown);
}

describe("createImageDimensionsRenderer", () => {
    it("wraps a plain image in .note-image-wrap with the resize handle", () => {
        const html = render("![cat](/img/cat.png)");

        expect(html).toContain('class="note-image-wrap"');
        expect(html).toContain('src="/img/cat.png"');
        expect(html).toContain('alt="cat"');
        expect(html).toContain('data-md-src="/img/cat.png"');
        expect(html).toContain('class="note-image-handle"');
        // No dimensions in alt → no inline style or width/height attribute
        // (data-md-width="" is still present as a placeholder).
        expect(html).not.toContain("style=");
        expect(html).not.toMatch(/\bwidth="\d/);
    });

    it("parses |width and sets style + width attribute", () => {
        const html = render("![cat|320](/img/cat.png)");

        expect(html).toContain('alt="cat"');
        expect(html).toContain('width="320"');
        expect(html).toContain("width: 320px");
        expect(html).toContain('data-md-width="320"');
    });

    it("parses |WxH and sets both dimensions", () => {
        const html = render("![cat|320x180](/img/cat.png)");

        expect(html).toContain('width="320"');
        expect(html).toContain('height="180"');
        expect(html).toContain("width: 320px");
        expect(html).toContain("height: 180px");
    });

    it("strips the |dim suffix from the displayed alt text", () => {
        const html = render("![A long caption|400](/img/cat.png)");

        expect(html).toContain('alt="A long caption"');
        expect(html).not.toContain('alt="A long caption|400"');
    });

    it("escapes html characters in href and alt", () => {
        const html = render('![<script>](/path?q="a"&b=1)');

        expect(html).toContain("&lt;script&gt;");
        expect(html).toContain("&quot;a&quot;");
        expect(html).not.toContain("<script>");
    });
});

describe("updateImageDimensionInContent", () => {
    it("appends |width when no dimension exists", () => {
        const result = updateImageDimensionInContent(
            "Some ![alt](/img/a.png) text",
            "/img/a.png",
            320,
        );
        expect(result).toBe("Some ![alt|320](/img/a.png) text");
    });

    it("replaces an existing |width", () => {
        const result = updateImageDimensionInContent(
            "![alt|200](/img/a.png)",
            "/img/a.png",
            500,
        );
        expect(result).toBe("![alt|500](/img/a.png)");
    });

    it("replaces an existing |WxH suffix with a single width", () => {
        const result = updateImageDimensionInContent(
            "![alt|200x120](/img/a.png)",
            "/img/a.png",
            420,
        );
        expect(result).toBe("![alt|420](/img/a.png)");
    });

    it("rounds non-integer widths and clamps to at least 1px", () => {
        expect(
            updateImageDimensionInContent("![a](/x.png)", "/x.png", 99.6),
        ).toBe("![a|100](/x.png)");
        expect(
            updateImageDimensionInContent("![a](/x.png)", "/x.png", -50),
        ).toBe("![a|1](/x.png)");
    });

    it("leaves content untouched when the src does not match", () => {
        const source = "![alt](/other.png)";
        expect(updateImageDimensionInContent(source, "/img/a.png", 200)).toBe(
            source,
        );
    });

    it("preserves a title parenthesis suffix", () => {
        const result = updateImageDimensionInContent(
            '![alt](/img/a.png "A title")',
            "/img/a.png",
            300,
        );
        expect(result).toBe('![alt|300](/img/a.png "A title")');
    });

    it("only rewrites the first occurrence", () => {
        const source = "![a](/img/x.png) ![a](/img/x.png)";
        const result = updateImageDimensionInContent(source, "/img/x.png", 200);
        expect(result).toBe("![a|200](/img/x.png) ![a](/img/x.png)");
    });

    it("noops on null/undefined inputs", () => {
        expect(updateImageDimensionInContent("", "/x.png", 100)).toBe("");
        expect(updateImageDimensionInContent("hi", "", 100)).toBe("hi");
        expect(updateImageDimensionInContent("hi", "/x.png", Number.NaN)).toBe(
            "hi",
        );
    });
});
