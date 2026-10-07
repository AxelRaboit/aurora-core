import { describe, it, expect } from "vitest";
import { createAppI18n, frenchPlural } from "./i18n.js";

/**
 * A named format vue-i18n cannot resolve renders as an empty string, silently.
 * That is how the submissions list came to read "SUB-000001 · · fr": nothing
 * declared "short", so every date in the product was blank.
 *
 * The list below is what the components actually ask for - grep for `d(` with
 * a string second argument and it is these two, in these three languages.
 */
describe("createAppI18n", () => {
    const at = new Date("2026-03-02T14:30:00Z");

    for (const locale of ["fr", "en", "es"]) {
        for (const format of ["short", "long"]) {
            it(`formats a date as "${format}" in ${locale}`, () => {
                const rendered = createAppI18n(locale).global.d(at, format);

                expect(rendered).not.toBe("");
                expect(rendered).toMatch(/2026/);
            });
        }
    }

    /** "long" is the all-day shape: a day, and no clock beside it. */
    it("leaves the clock out of the long format", () => {
        expect(createAppI18n("fr").global.d(at, "long")).not.toMatch(
            /\d{1,2}:\d{2}/,
        );
    });
});

/**
 * French puts zero in the singular. vue-i18n's default rule does not, which
 * wrote « 0 dossiers » under every empty folder.
 */
describe("frenchPlural", () => {
    it("reads 0 and 1 in the singular, from 2 in the plural", () => {
        expect([0, 1, 2, 12].map((count) => frenchPlural(count, 2))).toEqual([
            0, 0, 1, 1,
        ]);
    });

    it("keeps a zero form when the message has one", () => {
        expect([0, 1, 2, 12].map((count) => frenchPlural(count, 3))).toEqual([
            0, 1, 2, 2,
        ]);
    });

    it("is the rule French messages are chosen with", () => {
        const { global } = createAppI18n("fr");
        const key = "notes.markdown.folders.folder_count";

        expect(global.t(key, { count: 0 })).toBe("0 dossier");
        expect(global.t(key, { count: 1 })).toBe("1 dossier");
        expect(global.t(key, { count: 3 })).toBe("3 dossiers");
    });
});
