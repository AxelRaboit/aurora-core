/**
 * Formulas: `$…$` inside a line and `$$…$$` on lines of their own, as in
 * Obsidian and Notion.
 *
 * The renderer only leaves the source in a marked element: KaTeX is loaded
 * when a note actually holds a formula, by `noteHtmlEnhancer.js`, and a page
 * without one never downloads it. Until then, and if it fails, the reader
 * sees the formula as written, which is still the formula.
 *
 * A dollar sign is also money. Obsidian's rule keeps prices out: the opening
 * `$` is not followed by a space, the closing one is not preceded by one, and
 * a digit right after the closing one means it was a price ("$5 and $10").
 */
const INLINE = /^\$(?=[^\s$])((?:\\.|[^\\$\n])*?[^\s\\$])\$(?!\d)/;
const BLOCK = /^\$\$[ \t]*\n([\s\S]+?)\n\$\$[ \t]*(?:\n|$)/;

export function createInlineMathExtension() {
    return {
        name: "inlineMath",
        level: "inline",
        start(source) {
            const index = source.indexOf("$");

            return -1 === index ? undefined : index;
        },
        tokenizer(source) {
            const match = INLINE.exec(source);
            if (!match) return undefined;

            return { type: "inlineMath", raw: match[0], text: match[1] };
        },
        renderer(token) {
            return `<span class="md-math" data-math="${esc(token.text)}">${esc(token.text)}</span>`;
        },
    };
}

export function createBlockMathExtension() {
    return {
        name: "blockMath",
        level: "block",
        start(source) {
            const match = /(^|\n)\$\$/.exec(source);

            return match ? match.index + match[1].length : undefined;
        },
        tokenizer(source) {
            const match = BLOCK.exec(source);
            if (!match) return undefined;

            return { type: "blockMath", raw: match[0], text: match[1].trim() };
        },
        renderer(token) {
            return `<div class="md-math md-math-block" data-math="${esc(token.text)}" data-display="1">${esc(token.text)}</div>\n`;
        },
    };
}

/**
 * `[[toc]]` or `[TOC]` alone on a line: the note's table of contents, kept up
 * to date. Filled from the rendered headings by the enhancer, so it lists
 * exactly what the reader sees.
 */
export function createTableOfContentsExtension() {
    return {
        name: "tableOfContents",
        level: "block",
        start(source) {
            const match = /(^|\n)(\[\[toc\]\]|\[TOC\])/i.exec(source);

            return match ? match.index + match[1].length : undefined;
        },
        tokenizer(source) {
            const match = /^(?:\[\[toc\]\]|\[TOC\])[ \t]*(?:\n|$)/i.exec(
                source,
            );
            if (!match) return undefined;

            return { type: "tableOfContents", raw: match[0] };
        },
        renderer() {
            return `<nav class="md-toc" data-toc="1"></nav>\n`;
        },
    };
}

function esc(text) {
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}
