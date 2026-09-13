import { describe, expect, it } from "vitest";
import { emphasis } from "./emphasis.js";

/**
 * The slide text formatter, and mostly what it refuses.
 *
 * The same component draws the public share page, so this text reaches a
 * browser that never authenticated. The parser is restrictive and the
 * sanitiser is restrictive, and both are needed: `parseInline` passes raw HTML
 * through untouched.
 */
describe("emphasis", () => {
    it("marks bold, italic and code", () => {
        expect(emphasis("**gras** et *italique* et `code`")).toBe(
            "<strong>gras</strong> et <em>italique</em> et <code>code</code>",
        );
    });

    it("never produces a block element", () => {
        expect(emphasis("# Un titre")).toBe("# Un titre");
        expect(emphasis("- une puce")).toBe("- une puce");
    });

    it("drops a link but keeps the words it wrapped", () => {
        expect(emphasis("voir [le rapport](https://exemple.test)")).toBe(
            "voir le rapport",
        );
    });

    it("strips markup a slide never asked for", () => {
        expect(emphasis('<img src=x onerror="alert(1)">')).toBe("");
        expect(emphasis("<script>alert(1)</script>bonjour")).not.toContain(
            "<script",
        );
    });

    it("keeps an empty value empty rather than printing undefined", () => {
        expect(emphasis(null)).toBe("");
        expect(emphasis(undefined)).toBe("");
        expect(emphasis("")).toBe("");
    });
});
