import {
    BookOpen,
    CreditCard,
    ShieldCheck,
    SlidersHorizontal,
} from "lucide-vue-next";

/**
 * The help a screen can offer without leaving it.
 *
 * A hint under a field has one line to work with, and a documentation page
 * asks someone to go elsewhere and come back. Between the two there is room
 * for three paragraphs opened on demand, which is what a topic is.
 *
 * Content lives in the translation files, not here: Aurora ships in three
 * languages and a paragraph written inside a component would only ever exist
 * in one. A topic declares its shape - which kind it is, which sections it has
 * and in what order - and the strings are looked up under
 * `suite.help.<topic>.`.
 */

/**
 * What a topic is about, which decides its icon and accent.
 *
 * Not a decoration: someone who has seen the shield once knows the next shield
 * is also about who can read what, before reading a word. Keep the list short
 * - a kind per topic would be no kind at all.
 */
export const HelpKind = Object.freeze({
    /** How a field or a control behaves. The default, and the common case. */
    Field: "field",
    /** A notion the screen assumes: what a disk is, what a rendition is. */
    Concept: "concept",
    /** Who can read or write what, and what leaves the server. */
    Security: "security",
    /** What a choice costs, in money or in requests. */
    Billing: "billing",
});

export const HELP_KIND_ICONS = Object.freeze({
    [HelpKind.Field]: SlidersHorizontal,
    [HelpKind.Concept]: BookOpen,
    [HelpKind.Security]: ShieldCheck,
    [HelpKind.Billing]: CreditCard,
});

export const HELP_KIND_CLASSES = Object.freeze({
    [HelpKind.Field]: "text-secondary hover:text-primary",
    [HelpKind.Concept]:
        "text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300",
    [HelpKind.Security]:
        "text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300",
    [HelpKind.Billing]:
        "text-amber-600 hover:text-amber-700 dark:text-amber-400 dark:hover:text-amber-300",
});

/**
 * Every topic, by id.
 *
 * `sections` is an ordered list of suffixes; each one resolves to a
 * `<section>_title` and a `<section>_body` under the topic's own prefix. A
 * topic with no section is a single body, which is enough for most fields.
 */
export const HELP_TOPICS = Object.freeze({
    "storage.delivery_mode": {
        kind: HelpKind.Concept,
        sections: ["address", "proxy", "presigned", "public_url", "choosing"],
    },
    "storage.connection": {
        kind: HelpKind.Security,
        sections: ["what_is_tested", "what_is_stored", "disconnecting"],
    },
});

/** The topic, or null when the id is unknown - a missing topic hides the button rather than breaking the screen. */
export function helpTopic(id) {
    return HELP_TOPICS[id] ?? null;
}
