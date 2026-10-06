import { describe, it, expect } from "vitest";
import { createRequire } from "node:module";

/**
 * The patched copy of `braces` (tools/patched/braces), the one the override
 * in pnpm-workspace.yaml forces on the whole tree.
 *
 * GHSA-vfj7-8cjw-p6xm: braces 3.0.3, the latest published version, walks its
 * braces with recursive functions and no depth limit. Five thousand nested
 * braces, under its length limit, crashed Node with "Maximum call stack size
 * exceeded". The copy refuses nesting deeper than 500 levels, the same way
 * braces already refuses an input that is too long.
 *
 * What would be lost silently: the override, removed or bypassed by a tree
 * update, and the old copy served again. Hence the resolution through the
 * real chain, `micromatch` first.
 */
const require = createRequire(import.meta.url);
const micromatchPath = require.resolve("micromatch", {
    paths: [
        require.resolve("fast-glob", {
            paths: [require.resolve("vite-plugin-symfony")],
        }),
    ],
});
const braces = require(require.resolve("braces", { paths: [micromatchPath] }));

function nested(depth) {
    return `${"{".repeat(depth)}a,b${"}".repeat(depth)}`;
}

describe("patched braces", () => {
    it("still expands an ordinary pattern", () => {
        expect(braces("a/{b,c}/{d,{e,f}}", { expand: true })).toEqual([
            "a/b/d",
            "a/b/e",
            "a/b/f",
            "a/c/d",
            "a/c/e",
            "a/c/f",
        ]);
    });

    it("refuses a pattern nested too deep instead of overflowing the stack", () => {
        expect(() => braces(nested(4990), { expand: true })).toThrow(
            SyntaxError,
        );
        expect(() => braces(nested(4990), { expand: true })).toThrow(
            /max depth/,
        );
    });

    it("accepts a deep but reasonable nesting", () => {
        expect(() => braces(nested(100), { expand: true })).not.toThrow();
    });
});
