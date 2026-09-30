import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT, sourcesEndingWith } from "@/tests/helpers/phpSources.js";

/**
 * The second argument of `request()` is the body, not the options.
 *
 * `request(url, { method: "GET" })` reads like a fetch call and sends a POST
 * whose body is `{"method":"GET"}`. A GET-only route refuses it, and the screen
 * shows its generic failure: both contract previews did exactly that, the
 * template's and the contract's, and nothing in a test mounted them against
 * their real route.
 */

/** Where each `request(` call's first argument ends, strings and nesting skipped. */
function secondArguments(src) {
    const found = [];
    const pattern = /(?<![\w.$])request\(/g;
    let match;

    while ((match = pattern.exec(src)) !== null) {
        let depth = 0;
        let quote = null;

        for (let i = match.index + match[0].length; i < src.length; i += 1) {
            const char = src[i];

            if (quote) {
                if ("\\" === char) i += 1;
                else if (char === quote) quote = null;
                continue;
            }

            if ("\"'`".includes(char)) quote = char;
            else if ("([{".includes(char)) depth += 1;
            else if (")]}".includes(char)) {
                if (0 === depth) break;
                depth -= 1;
            } else if ("," === char && 0 === depth) {
                const line = src.slice(0, match.index).split("\n").length;
                found.push({ line, rest: src.slice(i + 1, i + 80) });
                break;
            }
        }
    }

    return found;
}

describe("request() arguments", () => {
    it("never passes the options where the body goes", () => {
        const offenders = sourcesEndingWith([".vue", ".js"])
            .filter((file) => !file.endsWith(".test.js"))
            .flatMap((file) =>
                secondArguments(fs.readFileSync(file, "utf8"))
                    .filter(({ rest }) => /^\s*\{\s*method\s*:/.test(rest))
                    .map(
                        ({ line }) =>
                            `${path.relative(REPO_ROOT, file)}:${line}`,
                    ),
            );

        expect(offenders).toEqual([]);
    });

    it("sees the mistake it guards against", () => {
        const [call] = secondArguments(
            'await request(`${a}?b=${c}`, {\n    method: "GET",\n});',
        );

        expect(call.rest).toMatch(/^\s*\{\s*method\s*:/);
    });
});
