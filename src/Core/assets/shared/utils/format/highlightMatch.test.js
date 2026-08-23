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
});
