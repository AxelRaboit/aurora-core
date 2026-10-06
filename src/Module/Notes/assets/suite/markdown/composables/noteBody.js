/**
 * A note's body, without the title it repeats at the top.
 *
 * An Aurora note carries its title in a field, and almost all of them write
 * it again as `# Titre` on their first line - it is what a markdown editor
 * does when nothing holds the title in its place. Everywhere the screen
 * already shows the title next to the body (the card, the reading page, the
 * editor preview), it was therefore read twice in a row.
 *
 * **The text is not touched.** Nothing is removed in the database, nothing
 * in the export, nothing in the writing area: it is the rendering that stops
 * saying the same thing twice. A title changed later simply makes the
 * original `# ` reappear in the rendering, which is the right surprise - the
 * note's text has not moved under its author's feet.
 *
 * And only when both say exactly the same thing: a document whose first
 * line is a real, different title keeps its title.
 */

/** `# Titre` at the top, with the blank lines before or after it. */
const LEADING_HEADING = /^\s*#\s+(.+?)\s*(?:\n+|$)/;

/**
 * @param {string} markdown the note's body
 * @param {string} title what the screen already shows next to it
 * @returns {string} the body, minus its repeated title
 */
export function withoutLeadingTitle(markdown, title) {
    const body = String(markdown ?? "");
    const heading = LEADING_HEADING.exec(body);

    if (!heading) return body;

    if (!same(heading[1], title)) return body;

    return body.slice(heading[0].length);
}

/** A reader's comparison: neither case nor spaces count. */
function same(a, b) {
    return (
        "" !== String(b ?? "").trim() &&
        String(a).trim().toLocaleLowerCase() ===
            String(b).trim().toLocaleLowerCase()
    );
}
