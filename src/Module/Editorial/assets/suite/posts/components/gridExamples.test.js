import { describe, expect, it } from "vitest";
import { createAppI18n } from "@/i18n.js";
import fr from "@/locales/generated/fr.json";

/**
 * The grid editor's example texts, compiled the way the screen compiles them.
 *
 * They used to be French strings written in the template, shown as-is to an
 * English or Spanish back office. As messages, two characters bite: `|` splits
 * a message into plural forms (a travel map example came back as « 51.5 »),
 * and `@` starts a linked key (an email example failed to compile). Both are
 * escaped in the YAML; this holds them escaped.
 */
const KEYS = Object.keys(fr.suite.posts.grid.examples);

describe.each(["fr", "en"])("the grid editor's examples in %s", (locale) => {
    const { t } = createAppI18n(locale).global;

    it.each(KEYS)("%s compiles to its whole text", (key) => {
        const text = t(`suite.posts.grid.examples.${key}`);

        expect(text).not.toBe(`suite.posts.grid.examples.${key}`);
        expect(text.length).toBeGreaterThan(1);
    });

    it("keeps the separators a format example shows", () => {
        expect(t("suite.posts.grid.examples.travel_stops")).toMatch(
            /Monument Valley \| 36\.9989 \| -110\.0980\n/,
        );
        expect(t("suite.posts.grid.examples.contact_email")).toContain("@");
    });
});
