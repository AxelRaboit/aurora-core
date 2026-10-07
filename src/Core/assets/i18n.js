import { createI18n } from "vue-i18n";
import { deepMerge } from "@/shared/utils/data/deepMerge.js";
import { datetimeFormats } from "@/datetimeFormats.js";

// Generated from translations/messages.{locale}.yaml via `php bin/console app:translations:dump-js`.
// Single source of truth for all Vue + Twig translations.
//
// One import per locale of LocaleEnum, and a language missing from this list
// is a language whose Vue components silently render in French - the Twig half
// of a page translated, the components on it not. See LocaleBundleTest, which
// exists because that is exactly what happened when Spanish was added.
import frYaml from "@/locales/generated/fr.json";
import enYaml from "@/locales/generated/en.json";
import esYaml from "@/locales/generated/es.json";

// Optional client-specific locale sources (e.g. custom module permission names).
// Resolves via the @client alias; returns {} when AURORA_CLIENT_DIR is unset.
// Keys use resolved paths, so we match by filename suffix instead of the alias literal.
const clientLocales = import.meta.glob("@client/src/locales/*.js", {
    eager: true,
});

function client(locale) {
    return (
        Object.entries(clientLocales).find(([path]) =>
            path.endsWith(`/${locale}.js`),
        )?.[1]?.default ?? {}
    );
}

// Client wins last so custom modules can override or extend any key.
const fr = deepMerge(frYaml, client("fr"));
const en = deepMerge(enYaml, client("en"));
const es = deepMerge(esYaml, client("es"));

/**
 * French counts zero with the singular: « 0 note », « 1 dossier », and the
 * plural from two on. vue-i18n's default rule puts zero in the plural, which
 * read « 0 dossiers » on every empty folder.
 *
 * Two forms (`one | other`): 0 and 1 take the first. Three forms
 * (`zero | one | other`) keep their meaning: 0, 1, then the rest.
 */
export function frenchPlural(choice, choicesLength) {
    const count = Math.abs(choice);

    if (choicesLength === 2) return count < 2 ? 0 : 1;

    return Math.min(count, choicesLength - 1);
}

export function createAppI18n(locale = "fr") {
    return createI18n({
        legacy: false,
        locale,
        // French is the source language, and it is what the Twig half falls
        // back to as well: a page half-translated should read in one language
        // rather than in two.
        fallbackLocale: "fr",
        messages: { fr, en, es },
        datetimeFormats,
        pluralRules: { fr: frenchPlural },
    });
}
