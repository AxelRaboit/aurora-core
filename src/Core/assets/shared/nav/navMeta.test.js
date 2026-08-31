import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { FileText, Tag } from "lucide-vue-next";
import { REPO_ROOT, phpSources } from "@/tests/helpers/phpSources.js";
import { ICON_MAP, resolveNavIcon } from "./navMeta.js";

/** The balanced `(...)` body of every `new NavItem(` call in one source. */
function navItemBodies(src) {
    const bodies = [];
    const opener = /new\s+NavItem\s*\(/g;
    let match;
    while ((match = opener.exec(src)) !== null) {
        const start = opener.lastIndex;
        let i = start;
        let depth = 1;
        while (depth > 0) {
            const c = src[i];
            if ("([{".includes(c)) depth += 1;
            else if (")]}".includes(c)) depth -= 1;
            else if ("'\"".includes(c)) {
                const quote = c;
                i += 1;
                while (src[i] !== quote) i += src[i] === "\\" ? 2 : 1;
            }
            i += 1;
        }
        bodies.push(src.slice(start, i - 1));
    }

    return bodies;
}

/** Split an argument list on its top-level commas, quotes and nesting aside. */
function splitArgs(body) {
    const args = [];
    let current = "";
    let depth = 0;
    for (let i = 0; i < body.length; i += 1) {
        const c = body[i];
        if ("([{".includes(c)) depth += 1;
        else if (")]}".includes(c)) depth -= 1;
        else if ("'\"".includes(c)) {
            const quote = c;
            current += c;
            i += 1;
            while (body[i] !== quote) {
                current += body[i];
                if (body[i] === "\\") {
                    i += 1;
                    current += body[i];
                }
                i += 1;
            }
            current += body[i];
            continue;
        }
        if ("," === c && 0 === depth) {
            args.push(current.trim());
            current = "";
            continue;
        }
        current += c;
    }
    if (current.trim()) args.push(current.trim());

    return args;
}

const NAMED_ARG = /^[A-Za-z_]\w*\s*:(?!:)/;

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
        const src = fs.readFileSync(file, "utf8");
        const where = path.relative(REPO_ROOT, file);
        const record = (name) => {
            if (!found.has(name)) found.set(name, where);
        };

        for (const body of navItemBodies(src)) {
            const args = splitArgs(body);
            const named = args.find((a) => /^icon\s*:(?!:)/.test(a));
            const positional = args.filter((a) => !NAMED_ARG.test(a));
            const expr = named
                ? named.split(":").slice(1).join(":")
                : positional[2];
            if (!expr) continue;

            for (const m of expr.matchAll(literal)) record(m[1]);
        }

        for (const m of src.matchAll(
            /const\s+array\s+\w*ICONS\w*\s*=\s*\[([^\]]*)\]/g,
        )) {
            for (const value of m[1].matchAll(/=>\s*'([a-z0-9][a-z0-9-]*)'/g)) {
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
     * tag and nothing anywhere says so. `backend_ged_tags` did exactly that.
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
