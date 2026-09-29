import { describe, expect, it } from "vitest";
import { highlightMatch } from "@/shared/utils/format/highlightMatch.js";

describe("highlightMatch", () => {
    it("returns text unchanged when query is empty", () => {
        expect(highlightMatch("hello world", "")).toBe("hello world");
        expect(highlightMatch("hello world", null)).toBe("hello world");
    });

    it("returns empty string when text is null/undefined", () => {
        expect(highlightMatch(null, "x")).toBe("");
        expect(highlightMatch(undefined, "x")).toBe("");
    });

    it("wraps matches with mark tags case-insensitively", () => {
        const result = highlightMatch("Hello World", "hello");
        expect(result).toContain("<mark");
        expect(result).toMatch(/>Hello</);
    });

    it("ignores tokens shorter than 2 characters", () => {
        // Single-character tokens are filtered out - prevents wrapping every "a".
        const result = highlightMatch("apple", "a");
        expect(result).toBe("apple");
    });

    it("escapes regex metacharacters in query", () => {
        const result = highlightMatch("foo.bar", ".bar");
        // The "." was escaped, so only literal ".bar" matches, not anything-bar.
        expect(result).toContain("<mark");
    });

    it("highlights multiple tokens", () => {
        const result = highlightMatch("the quick brown fox", "quick fox");
        expect(result.match(/<mark/g)).toHaveLength(2);
    });

    it("escapes markup in the text, so a title cannot inject HTML", () => {
        const result = highlightMatch(
            '<img src=x onerror="alert(1)"> licorne',
            "licorne",
        );
        expect(result).not.toContain("<img");
        expect(result).toContain(
            "&lt;img src=x onerror=&quot;alert(1)&quot;&gt;",
        );
        expect(result).toContain(">licorne</mark>");
    });

    it("escapes markup even when there is nothing to highlight", () => {
        expect(highlightMatch("<b>gras</b>", "")).toBe(
            "&lt;b&gt;gras&lt;/b&gt;",
        );
    });

    it("still finds a query holding an ampersand", () => {
        expect(highlightMatch("Durand & fils", "& fils")).toContain(
            ">fils</mark>",
        );
        expect(highlightMatch("R&D", "r&d")).toContain(">R&amp;D</mark>");
    });
});
