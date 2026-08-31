import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import {
    REPO_ROOT,
    phpSources,
    sourcesEndingWith,
} from "@/tests/helpers/phpSources.js";

/**
 * A `vue_component` path as the panel contract spells it:
 * `<module>/backend/<dirs>/<Component>`.
 */
const PANEL_PATH =
    /'([a-z0-9-]+\/backend\/[A-Za-z0-9/_-]+\/[A-Z][A-Za-z0-9]+)'/g;

/** Panel names the PHP modules name, from the classes allowed to name one. */
function declaredPanels() {
    const found = new Map();

    for (const file of phpSources()) {
        if (!file.endsWith("Module.php")) continue;

        const src = fs.readFileSync(file, "utf8");
        for (const match of src.matchAll(PANEL_PATH)) {
            if (!found.has(match[1])) {
                found.set(match[1], path.relative(REPO_ROOT, file));
            }
        }
    }

    return found;
}

/** Panel names a `*.register.js` actually claims. */
function registeredPanels() {
    const found = new Map();
    const call = /registerModulePanel\(\s*["']([^"']+)["']/g;

    for (const file of sourcesEndingWith([".register.js"])) {
        const src = fs.readFileSync(file, "utf8");
        for (const match of src.matchAll(call)) {
            if (!found.has(match[1])) {
                found.set(match[1], path.relative(REPO_ROOT, file));
            }
        }
    }

    return found;
}

describe("the module panel registry", () => {
    /**
     * The two sides are matched by an identical string written twice, in two
     * languages, and nothing checks them at build time. The failure is silent
     * by design: `getModulePanel` returns null for a name nothing claimed and
     * the menu draws its links anyway - correct behaviour for a typo, and
     * indistinguishable from a panel that was never registered.
     */
    it("registers every panel the PHP modules name", () => {
        const missing = [...declaredPanels()]
            .filter(([name]) => !registeredPanels().has(name))
            .map(([name, where]) => `${name} (declared in ${where})`);

        expect(missing).toEqual([]);
    });

    /** The other direction: a registration nothing asks for is dead weight. */
    it("has no registration no module asks for", () => {
        const orphans = [...registeredPanels()]
            .filter(([name]) => !declaredPanels().has(name))
            .map(([name, where]) => `${name} (registered in ${where})`);

        expect(orphans).toEqual([]);
    });
});
