/**
 * Highlighted and coloured text, the two marks a note had no way to write.
 *
 * - `==text==` highlights, as in Obsidian; `=={green}text==` picks the colour.
 * - `{red}text{/}` colours the letters themselves, as Notion and Craft do.
 *
 * Colours by name rather than by value: a note written today must still read
 * well on tomorrow's theme and in the dark one, which a hex code cannot
 * promise. French and English names both work, since the note is written by
 * whoever writes it; an unknown name leaves the text as it was typed.
 */
export const MARK_COLORS = {
    yellow: ["jaune", "yellow"],
    green: ["vert", "green"],
    blue: ["bleu", "blue"],
    purple: ["violet", "purple"],
    pink: ["rose", "pink"],
    red: ["rouge", "red"],
    orange: ["orange"],
    gray: ["gris", "gray", "grey"],
};

/** A written colour name, French or English, to the class suffix; null if unknown. */
export function markColor(name) {
    const wanted = String(name ?? "")
        .trim()
        .toLowerCase();
    if ("" === wanted) return null;

    for (const [color, names] of Object.entries(MARK_COLORS)) {
        if (names.includes(wanted)) return color;
    }

    return null;
}

const HIGHLIGHT = /^==(?:\{([a-zA-Zéè]+)\})?(?=\S)([\s\S]*?\S)==(?!=)/;
const TEXT_COLOR = /^\{([a-zA-Zéè]+)\}(?=\S)([\s\S]*?\S)\{\/\}/;

export function createHighlightMarkExtension() {
    return {
        name: "highlightMark",
        level: "inline",
        start(source) {
            const index = source.indexOf("==");

            return -1 === index ? undefined : index;
        },
        tokenizer(source) {
            const match = HIGHLIGHT.exec(source);
            if (!match) return undefined;

            const color =
                undefined === match[1] ? "yellow" : markColor(match[1]);
            if (null === color) return undefined;

            return {
                type: "highlightMark",
                raw: match[0],
                color,
                tokens: this.lexer.inlineTokens(match[2]),
            };
        },
        renderer(token) {
            return `<mark class="md-mark md-mark-${token.color}">${this.parser.parseInline(token.tokens)}</mark>`;
        },
    };
}

export function createTextColorExtension() {
    return {
        name: "textColor",
        level: "inline",
        start(source) {
            const match = /\{[a-zA-Zéè]+\}/.exec(source);

            return match ? match.index : undefined;
        },
        tokenizer(source) {
            const match = TEXT_COLOR.exec(source);
            if (!match) return undefined;

            const color = markColor(match[1]);
            if (null === color) return undefined;

            return {
                type: "textColor",
                raw: match[0],
                color,
                tokens: this.lexer.inlineTokens(match[2]),
            };
        },
        renderer(token) {
            return `<span class="md-color md-color-${token.color}">${this.parser.parseInline(token.tokens)}</span>`;
        },
    };
}
