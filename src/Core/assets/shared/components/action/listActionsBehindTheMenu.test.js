import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT, sourcesEndingWith } from "@/tests/helpers/phpSources.js";

/**
 * A list's actions sit behind the « … » button, everywhere.
 *
 * Axel's call of 04/10/2026: on a phone card as in a table, the actions of a
 * row go through `AppRowActions`, which shows a single action as its own
 * button and several behind the menu. Before that, a dozen cards laid them
 * out as a stack of full-width buttons (`AppCardActions`, now gone) or looped
 * over the action list with `AppButton`. This keeps the second shape from
 * coming back: an action list rendered as buttons by hand.
 */

/** A `v-for` over an action list whose element is a button. */
function laidOutActions(src) {
    return [
        ...src.matchAll(
            /<(AppButton|AppActionButton|button)\b[^>]*v-for="\s*\w+\s+in\s+([^"]*[Aa]ctions[^"]*)"/g,
        ),
    ].map((match) => match[2].trim());
}

describe("list actions", () => {
    it("are never laid out as a row of buttons", () => {
        const offenders = sourcesEndingWith([".vue"])
            // The sheet and the page's « Actions » button draw the rows themselves.
            .filter(
                (file) =>
                    !file.includes(
                        `${path.sep}components${path.sep}action${path.sep}`,
                    ),
            )
            .flatMap((file) =>
                laidOutActions(fs.readFileSync(file, "utf8")).map(
                    (list) => `${path.relative(REPO_ROOT, file)}: ${list}`,
                ),
            );

        expect(offenders).toEqual([]);
    });

    it("sees the mistake it guards against", () => {
        expect(
            laidOutActions(
                '<AppButton\n    v-for="action in rowActions(template)"\n    :key="action.key"\n>',
            ),
        ).toEqual(["rowActions(template)"]);
    });
});
