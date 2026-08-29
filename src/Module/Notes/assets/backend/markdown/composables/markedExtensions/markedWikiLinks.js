/**
 * Marked inline extension for Obsidian-style wiki-links: `[[Title]]` or
 * `[[Title#heading]]`. The rendered anchor carries a `data-note-title`
 * attribute so the host app can intercept clicks and navigate.
 *
 * `applyWikiLinksToHtml` is a fallback for the rare case where marked's
 * built-in link tokenizer consumes the leading `[` (inside list items)
 * before our inline extension matches.
 */
export function createWikiLinkExtension() {
    return {
        name: "wikiLink",
        level: "inline",
        start(src) {
            const i = src.indexOf("[[");
            return i === -1 ? undefined : i;
        },
        tokenizer(src) {
            const match = src.match(/^\[\[([^\]]+)\]\]/);
            if (!match) return undefined;
            const raw = match[1].trim();
            const hashIndex = raw.indexOf("#");
            const noteTitle =
                hashIndex === -1 ? raw : raw.slice(0, hashIndex).trim();
            const heading =
                hashIndex === -1 ? "" : raw.slice(hashIndex + 1).trim();
            return { type: "wikiLink", raw: match[0], noteTitle, heading };
        },
        renderer(token) {
            return renderWikiLink(token.noteTitle, token.heading);
        },
    };
}

export function applyWikiLinksToHtml(html) {
    return html.replace(/\[\[([^\]<>]+?)\]\]/g, (_match, inner) => {
        const trimmed = inner.trim();
        const hashIndex = trimmed.indexOf("#");
        const noteTitle =
            hashIndex === -1 ? trimmed : trimmed.slice(0, hashIndex).trim();
        const heading =
            hashIndex === -1 ? "" : trimmed.slice(hashIndex + 1).trim();
        return renderWikiLink(noteTitle, heading);
    });
}

function renderWikiLink(noteTitle, heading) {
    const display =
        heading && noteTitle
            ? `${noteTitle} > ${heading}`
            : heading || noteTitle;
    return `<a class="wiki-link" data-note-title="${esc(noteTitle)}" data-heading="${esc(heading)}">${esc(display)}</a>`;
}

function esc(str) {
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}
