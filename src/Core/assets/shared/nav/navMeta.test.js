import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { FileText, Tag } from "lucide-vue-next";
import { REPOSITORY_ROOT, phpSources } from "@/tests/helpers/phpSources.js";
import { ICON_MAP, resolveNavIcon } from "./navMeta.js";

/** The balanced `(...)` body of every `new NavItem(` call in one source. */
function navItemBodies(source) {
    const bodies = [];
    const opener = /new\s+NavItem\s*\(/g;
    let match;
    while ((match = opener.exec(source)) !== null) {
        const start = opener.lastIndex;
        let position = start;
        let depth = 1;
        // Bounded by the source: a stray quote (an apostrophe in a comment)
        // must fail the test, not spin it for ever.
        while (depth > 0 && position < source.length) {
            const character = source[position];
            if ("([{".includes(character)) depth += 1;
            else if (")]}".includes(character)) depth -= 1;
            else if ("'\"".includes(character)) {
                const quote = character;
                position += 1;
                while (position < source.length && source[position] !== quote)
                    position += source[position] === "\\" ? 2 : 1;
            }
            position += 1;
        }
        bodies.push(source.slice(start, position - 1));
    }

    return bodies;
}

/** Split an argument list on its top-level commas, quotes and nesting aside. */
function splitArgs(body) {
    const topLevelArguments = [];
    let current = "";
    let depth = 0;
    for (let position = 0; position < body.length; position += 1) {
        const character = body[position];
        if ("([{".includes(character)) depth += 1;
        else if (")]}".includes(character)) depth -= 1;
        else if ("'\"".includes(character)) {
            const quote = character;
            current += character;
            position += 1;
            while (position < body.length && body[position] !== quote) {
                current += body[position];
                if (body[position] === "\\") {
                    position += 1;
                    current += body[position];
                }
                position += 1;
            }
            current += body[position];
            continue;
        }
        if ("," === character && 0 === depth) {
            topLevelArguments.push(current.trim());
            current = "";
            continue;
        }
        current += character;
    }
    if (current.trim()) topLevelArguments.push(current.trim());

    return topLevelArguments;
}

const NAMED_ARGUMENT = /^[A-Za-z_]\w*\s*:(?!:)/;

/**
 * Icon names a module declares, read out of the PHP rather than restated here
 * - a list maintained by hand would be one more thing to forget to update, and
 * forgetting is the failure this test exists to catch.
 *
 * Two shapes are read. A `new NavItem(...)` icon argument, positional or
 * named, which is the common one; and the values of a `const *_ICONS` lookup,
 * because the settings tabs pick their icon out of one
 * (`ConfigurationModule::TAB_ICONS`) instead of writing it at the call site. A
 * non-literal argument contributes whatever literals it does contain, which
 * covers the `?? 'sliders-horizontal'` fallback next to that lookup.
 */
function declaredIconNames() {
    const found = new Map();
    const literal = /'([a-z0-9][a-z0-9-]*)'/g;

    for (const file of phpSources()) {
        const source = fs.readFileSync(file, "utf8");
        const where = path.relative(REPOSITORY_ROOT, file);
        const record = (name) => {
            if (!found.has(name)) found.set(name, where);
        };

        for (const body of navItemBodies(source)) {
            const callArguments = splitArgs(body);
            const named = callArguments.find((argument) =>
                /^icon\s*:(?!:)/.test(argument),
            );
            const positional = callArguments.filter(
                (argument) => !NAMED_ARGUMENT.test(argument),
            );
            const expr = named
                ? named.split(":").slice(1).join(":")
                : positional[2];
            if (!expr) continue;

            for (const iconMatch of expr.matchAll(literal))
                record(iconMatch[1]);
        }

        for (const constantMatch of source.matchAll(
            /const\s+array\s+\w*ICONS\w*\s*=\s*\[([^\]]*)\]/g,
        )) {
            for (const value of constantMatch[1].matchAll(
                /=>\s*'([a-z0-9][a-z0-9-]*)'/g,
            )) {
                record(value[1]);
            }
        }
    }

    return found;
}

describe("the nav icon map", () => {
    /**
     * The failure this pins is silent by construction: an unmapped name falls
     * back to FileText, so a module ships a document icon where it asked for a
     * tag and nothing anywhere says so. `suite_ged_tags` did exactly that.
     */
    it("has an entry for every icon name the PHP modules declare", () => {
        const declared = declaredIconNames();

        expect(declared.size).toBeGreaterThan(10);

        const missing = [...declared]
            .filter(([name]) => !(name in ICON_MAP))
            .map(([name, where]) => `${name} (${where})`);

        expect(missing).toEqual([]);
    });

    it("resolves a name to its own icon, not the fallback", () => {
        expect(resolveNavIcon("tag")).toBe(Tag);
        expect(resolveNavIcon("tag")).not.toBe(FileText);
    });

    it("falls back to FileText for a name it does not know", () => {
        expect(resolveNavIcon("no-such-icon")).toBe(FileText);
    });
});
