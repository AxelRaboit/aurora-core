import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/** Repository root, four levels above `src/Core/assets/tests/helpers`. */
export const REPO_ROOT = path.resolve(
    path.dirname(fileURLToPath(import.meta.url)),
    "../../../../..",
);

/**
 * Every `.php` file under `src`, absolute.
 *
 * A handful of guards assert that what the PHP modules declare - an icon name,
 * a panel component - has a counterpart on the Vue side. Those declarations
 * live in PHP and the maps live in JS, so the check has to cross the boundary;
 * reading the sources is the cheap way, and it beats restating the list by hand
 * in a test, which is one more thing to forget.
 *
 * @param {string} dir defaults to `src`
 * @returns {string[]}
 */
export function phpSources(dir = path.join(REPO_ROOT, "src")) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return phpSources(full);

        return entry.name.endsWith(".php") ? [full] : [];
    });
}

/** Every file under `src` whose name ends with one of `suffixes`. */
export function sourcesEndingWith(suffixes, dir = path.join(REPO_ROOT, "src")) {
    return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) return sourcesEndingWith(suffixes, full);

        return suffixes.some((s) => entry.name.endsWith(s)) ? [full] : [];
    });
}
