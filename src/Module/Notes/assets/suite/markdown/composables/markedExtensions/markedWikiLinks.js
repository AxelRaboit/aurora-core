/**
 * Marked inline extension for Obsidian-style wiki-links: `[[Title]]` or
 * `[[Title#heading]]`. The rendered anchor carries a `data-note-title`
 * attribute so the host app can intercept clicks and navigate.
 *
 * Three more forms since 09/10/2026, all Obsidian's:
 *
 * - `[[Title|shown text]]` leads to the note and reads as the text after the
 *   bar, so a sentence does not have to bend around a title;
 * - `[[Title#^id]]` leads to one paragraph of the note, the one ending with
 *   ` ^id` (see `applyBlockIdsToHtml`);
 * - `![[Title]]` alone on its line includes the note itself, rendered in
 *   place. The renderer only leaves a marker: fetching the note belongs to
 *   whoever displays it, the suite or a share page, which do not reach notes
 *   the same way (see `noteHtmlEnhancer.js`).
 *
 * `applyWikiLinksToHtml` is a fallback for the rare case where marked's
 * built-in link tokenizer consumes the leading `[` (inside list items)
 * before our inline extension matches.
 */
export function createWikiLinkExtension() {
    return {
        name: "wikiLink",
        level: "inline",
        start(source) {
            const openingIndex = source.indexOf("[[");
            return openingIndex === -1 ? undefined : openingIndex;
        },
        tokenizer(source) {
            const match = source.match(/^\[\[([^\]]+)\]\]/);
            if (!match) return undefined;

            return {
                type: "wikiLink",
                raw: match[0],
                ...parseWikiTarget(match[1]),
            };
        },
        renderer(token) {
            return renderWikiLink(token.noteTitle, token.heading, token.alias);
        },
    };
}

/** `![[Title]]` or `![[Title#heading]]` on a line of its own: a note included in this one. */
export function createWikiEmbedExtension() {
    return {
        name: "wikiEmbed",
        level: "block",
        start(source) {
            const match = /(^|\n)!\[\[/.exec(source);

            return match ? match.index + match[1].length : undefined;
        },
        tokenizer(source) {
            const match = source.match(/^!\[\[([^\]]+)\]\][ \t]*(?:\n|$)/);
            if (!match) return undefined;

            return {
                type: "wikiEmbed",
                raw: match[0],
                ...parseWikiTarget(match[1]),
            };
        },
        renderer(token) {
            return (
                `<div class="md-embed" data-embed-title="${esc(token.noteTitle)}" data-heading="${esc(token.heading)}">` +
                `<a class="wiki-link md-embed-title" data-note-title="${esc(token.noteTitle)}" data-heading="${esc(token.heading)}">${esc(token.alias || token.noteTitle)}</a>` +
                `</div>\n`
            );
        },
    };
}

/**
 * `Title#heading|alias` into its three parts. The bar comes first: in
 * `[[Note#Part|here]]` everything after it is what the reader sees.
 */
export function parseWikiTarget(inner) {
    const raw = String(inner ?? "").trim();
    const barIndex = raw.indexOf("|");
    const target = -1 === barIndex ? raw : raw.slice(0, barIndex).trim();
    const alias = -1 === barIndex ? "" : raw.slice(barIndex + 1).trim();
    const hashIndex = target.indexOf("#");
    const noteTitle =
        -1 === hashIndex ? target : target.slice(0, hashIndex).trim();
    const heading = -1 === hashIndex ? "" : target.slice(hashIndex + 1).trim();

    return { noteTitle, heading, alias };
}

export function applyWikiLinksToHtml(html) {
    return html.replace(/\[\[([^\]<>]+?)\]\]/g, (_match, inner) => {
        const { noteTitle, heading, alias } = parseWikiTarget(inner);

        return renderWikiLink(noteTitle, heading, alias);
    });
}

/**
 * The ` ^id` that ends a paragraph or a list item names it, so that
 * `[[Note#^id]]` can lead there. The marker leaves the text and becomes an
 * attribute on a mark the click router scrolls to.
 */
export function applyBlockIdsToHtml(html) {
    return html.replace(
        /[ \t]\^([A-Za-z0-9-]{1,40})(?=\s*<\/(?:p|li)>)/g,
        (_match, id) =>
            `<span class="md-block-id" data-block-id="${esc(id)}"></span>`,
    );
}

function renderWikiLink(noteTitle, heading, alias = "") {
    let display = alias;
    if ("" === display) {
        // A paragraph has no title of its own to show: the note's name says
        // where the link goes, the mark that it goes to a precise place.
        const shownHeading = heading.startsWith("^") ? "¶" : heading;
        display =
            shownHeading && noteTitle
                ? `${noteTitle} > ${shownHeading}`
                : shownHeading || noteTitle;
    }

    return `<a class="wiki-link" data-note-title="${esc(noteTitle)}" data-heading="${esc(heading)}">${esc(display)}</a>`;
}

function esc(text) {
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}
