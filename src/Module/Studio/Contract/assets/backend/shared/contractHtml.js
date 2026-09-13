import DOMPurify from "dompurify";

/**
 * The tags the contract renderer can emit, and nothing else.
 *
 * Shared by the two screens that put contract HTML into the DOM - the sealed
 * document and the template preview - because a list kept in two places is a
 * list that will differ. The day the renderer learns a new block, one entry
 * here makes both screens show it; without this, one of the two would silently
 * strip it and nobody would know which.
 */
const ALLOWED_TAGS = [
    "section",
    "h1",
    "h2",
    "h3",
    "h4",
    "p",
    "ul",
    "ol",
    "li",
    "blockquote",
    "footer",
    "table",
    "tr",
    "th",
    "td",
    "hr",
    "b",
    "strong",
    "i",
    "em",
    "u",
    "s",
    "mark",
    "code",
    "br",
    "span",
];

/**
 * Belt and braces: the renderer already sanitises every text and escapes every
 * substituted value, but this is HTML handed to `v-html`, and cleaning it here
 * can only ever remove something that had no business being there.
 *
 * @param {string} html
 * @returns {string}
 */
export function safeContractHtml(html) {
    return DOMPurify.sanitize(html ?? "", {
        ALLOWED_TAGS,
        ALLOWED_ATTR: ["class", "style"],
    });
}
