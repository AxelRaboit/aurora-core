const HIGHLIGHT_MARK =
    '<mark class="bg-accent-400/30 text-primary rounded px-0.5">$1</mark>';

const HTML_ENTITIES = {
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
};

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => HTML_ENTITIES[char]);
}

/**
 * The text as HTML, with the query's words wrapped in a `<mark>`.
 *
 * **Escaped before anything else**, because the result goes through `v-html`:
 * the text is a record's title - a card, a customer, a note - written by
 * somebody else, and a title holding markup would otherwise run in the palette
 * of whoever searches for it. The tokens are escaped the same way so that a
 * query containing `&` or `<` still finds its match in the escaped text.
 */
export function highlightMatch(text, query) {
    if (!text) return "";
    const safe = escapeHtml(text);
    if (!query) return safe;
    const tokens = query
        .trim()
        .split(/\s+/)
        .filter((token) => token.length > 1)
        .map((token) =>
            escapeHtml(token).replace(/[.*+?^${}()|[\]\\]/g, "\\$&"),
        );
    if (!tokens.length) return safe;
    const regex = new RegExp(`(${tokens.join("|")})`, "ig");
    return safe.replace(regex, HIGHLIGHT_MARK);
}
