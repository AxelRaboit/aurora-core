import { Marked } from "marked";
import DOMPurify from "dompurify";

/**
 * Emphasis inside a slide's text, and nothing more than emphasis.
 *
 * **Inline Markdown, not Markdown.** A slide is a shape with words in it; a
 * heading or a list typed into a title slot would be a second layout system
 * fighting the one the module chose. `parseInline` never produces block
 * elements, so `# Titre` on a slide stays the characters somebody typed.
 *
 * Three marks in the output: bold, italic and code. The parser understands
 * more than that - links, images, strikethrough - and the allowlist is what
 * says no to them. A link on a slide is ink that cannot be clicked from the
 * back of a room; an image would be a picture the layout never reserved room
 * for, drawn over whatever was there.
 *
 * The sanitiser is not ceremony even with a restrictive parser. This same
 * component draws the public share page, so the text reaches a browser that
 * never authenticated, and `parseInline` happily passes raw HTML through.
 */
/**
 * `==un mot==`, painted in the deck's accent.
 *
 * **A fourth mark, and the first one that is not about weight.** Lifting one
 * word of a title into the accent is the most common gesture in deck
 * typography, and until now the only way to reach it was to write the whole
 * title in the accent by overriding the deck's ink, which is not the same
 * thing at all.
 *
 * The parser does not know `==`, so it is declared here rather than borrowed
 * from a mark that means something else: making `~~struck~~` paint in colour
 * would be a lie the editor's own hint would have to keep.
 *
 * The inner text goes back through the inline lexer, so `==**mot**==` is a
 * bold accented word rather than four visible asterisks.
 */
const accentMark = {
    name: "accentMark",
    level: "inline",
    start(src) {
        return src.indexOf("==");
    },
    tokenizer(src) {
        const match = /^==(?=\S)([\s\S]*?\S)==/.exec(src);

        if (!match) return undefined;

        return {
            type: "accentMark",
            raw: match[0],
            tokens: this.lexer.inlineTokens(match[1]),
        };
    },
    renderer(token) {
        return `<mark>${this.parser.parseInline(token.tokens)}</mark>`;
    },
};

const marked = new Marked({ gfm: true }).use({ extensions: [accentMark] });

/** Everything else is unwrapped to its text rather than dropped with it. */
const ALLOWED_TAGS = ["strong", "b", "em", "i", "code", "mark"];

export function emphasis(text) {
    if (!text) return "";

    return DOMPurify.sanitize(marked.parseInline(String(text)), {
        ALLOWED_TAGS,
        ALLOWED_ATTR: [],
        // Text inside a tag nobody allowed is kept, so a stray `<span>` costs
        // its markup and not the sentence it wrapped.
        KEEP_CONTENT: true,
    });
}
