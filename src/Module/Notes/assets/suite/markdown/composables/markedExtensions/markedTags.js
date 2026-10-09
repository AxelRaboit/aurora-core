/**
 * `#tag` written in the text (09/10/2026), as in Obsidian: a chip in the
 * preview that filters the library by that tag, and a tag of the note once
 * saved (`mergeInlineTags`).
 *
 * At a word's start and with at least one letter, the same rule as
 * `inlineTags`: `# Title` is a heading, `C#` a language, `#1` a number.
 */
const TAG = /^#([\p{L}\p{N}_/-]*\p{L}[\p{L}\p{N}_/-]*)/u;

export function createInlineTagExtension() {
    return {
        name: "inlineTag",
        level: "inline",
        start(source) {
            const match = /(^|[\s(])#[\p{L}\p{N}_/-]*\p{L}/u.exec(source);

            return match ? match.index + match[1].length : undefined;
        },
        tokenizer(source) {
            const match = TAG.exec(source);
            if (!match) return undefined;

            return { type: "inlineTag", raw: match[0], tag: match[1] };
        },
        renderer(token) {
            const tag = String(token.tag)
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/"/g, "&quot;");

            return `<a class="md-tag" data-tag="${tag}">#${tag}</a>`;
        },
    };
}
