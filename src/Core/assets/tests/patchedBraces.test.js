import { describe, it, expect } from "vitest";
import { createRequire } from "node:module";

/**
 * La copie corrigée de `braces` (tools/patched/braces), celle que l'override
 * de pnpm-workspace.yaml impose à tout l'arbre.
 *
 * GHSA-vfj7-8cjw-p6xm : braces 3.0.3, la dernière version publiée, parcourt
 * ses accolades par des fonctions récursives sans limite de profondeur. Cinq
 * mille accolades imbriquées, sous sa limite de longueur, faisaient planter
 * Node sur « Maximum call stack size exceeded ». La copie refuse une
 * imbrication de plus de 500 niveaux, comme braces refuse déjà une entrée
 * trop longue.
 *
 * Ce qui se perdrait sans bruit : l'override, retiré ou contourné par une
 * mise à jour de l'arbre, et la vieille copie de nouveau servie. D'où la
 * résolution par la vraie chaîne, `micromatch` d'abord.
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
