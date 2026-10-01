import { describe, expect, it } from "vitest";
import fs from "node:fs";
import path from "node:path";
import { REPO_ROOT, sourcesEndingWith } from "@/tests/helpers/phpSources.js";

/**
 * « + Ajouter… » is grey, everywhere.
 *
 * The 31 add buttons of the back-office came in three looks: grey, a grey
 * block with a border, and green link text in the planning's reminders. Axel
 * chose grey (`ghost`) on 01/10/2026. This keeps the two other looks from
 * coming back: a `secondary` add button, or a raw button tinted with the
 * accent. A filled `primary` stays possible where it is the screen's main
 * action (an empty list's « Créer », a modal's submit).
 */

/** The opening tag of the button each `<Plus` sits in, by line. */
function plusButtons(src) {
    const found = [];
    const lines = src.split("\n");

    lines.forEach((line, index) => {
        if (!/<Plus\b/.test(line)) return;

        const before = lines
            .slice(Math.max(0, index - 12), index + 1)
            .join("\n");
        const start = Math.max(
            before.lastIndexOf("<AppButton"),
            before.lastIndexOf("<button"),
        );
        if (start < 0) return;

        const tag = before.slice(
            start,
            before.indexOf(">", start) + 1 || undefined,
        );
        found.push({ line: index + 1, tag });
    });

    return found;
}

function offends(tag) {
    return (
        /variant="secondary"/.test(tag) ||
        (/^<button/.test(tag) && /text-accent/.test(tag))
    );
}

describe("add buttons", () => {
    it("are never secondary nor tinted with the accent", () => {
        const offenders = sourcesEndingWith([".vue"]).flatMap((file) =>
            plusButtons(fs.readFileSync(file, "utf8"))
                .filter(({ tag }) => offends(tag))
                .map(({ line }) => `${path.relative(REPO_ROOT, file)}:${line}`),
        );

        expect(offenders).toEqual([]);
    });

    it("sees the mistake it guards against", () => {
        const [found] = plusButtons(
            '<AppButton variant="secondary" size="sm">\n    <Plus class="h-3.5 w-3.5" />',
        );

        expect(offends(found.tag)).toBe(true);
    });
});
