/**
 * The blanks of a model still waiting for their words: « [Nom de la
 * marque] », « [0,7 %] ».
 *
 * The same pattern as `PlaceholderMarkExtension` on the server, which lights
 * them in the preview: a pair of brackets around up to three hundred
 * characters, on one line, with no tag inside. Markup is stripped first, so a
 * bracket split by a `<b>` still counts once.
 */
const BLANK = /\[[^[\]<>\n]{1,300}\]/g;

/** How many blanks one string holds. */
export function countInText(value) {
    const text = String(value ?? "").replace(/<[^>]*>/g, "");

    return (text.match(BLANK) ?? []).length;
}

/**
 * How many blanks a whole value holds - a zone's content, a grid's, a
 * document's - by walking every string in it. Keys are not read: a field name
 * is never a blank.
 */
export function countPlaceholders(value) {
    if ("string" === typeof value) return countInText(value);
    if (Array.isArray(value))
        return value.reduce(
            (total, entry) => total + countPlaceholders(entry),
            0,
        );
    if (value && "object" === typeof value) {
        return Object.values(value).reduce(
            (total, entry) => total + countPlaceholders(entry),
            0,
        );
    }

    return 0;
}
