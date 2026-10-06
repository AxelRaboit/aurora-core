/**
 * A note's outline: its headings, in order, and its length.
 *
 * Craft and Notion show a page's headings next to it, to find one's way and
 * jump around; a long note (a brief, a procedure) reads badly without them.
 * The headings are read from the Markdown text itself, without going through
 * the rendering: the outline also serves in writing mode, where there is no
 * preview.
 *
 * What is in a code block does not count: a shell `# commentaire` is not a
 * heading, and its words are not the ones being read.
 */

/** The words read in one minute, on screen. */
const WORDS_PER_MINUTE = 220;

const FENCE = /^\s*(```|~~~)/;
const HEADING = /^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/;

/** A heading as it is read: without its link, emphasis or code syntax. */
function readable(text) {
    return text
        .replace(/\[\[([^\]|]+)\|([^\]]+)\]\]/g, "$2")
        .replace(/\[\[([^\]]+)\]\]/g, "$1")
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, "$1")
        .replace(/[*_~`]+/g, "")
        .trim();
}

/**
 * The text lines, outside code blocks, with their number.
 *
 * @param {string} markdown
 * @returns {Array<{line: number, text: string}>}
 */
function proseLines(markdown) {
    const lines = String(markdown ?? "").split("\n");
    const prose = [];
    let inFence = false;

    lines.forEach((text, line) => {
        if (FENCE.test(text)) {
            inFence = !inFence;

            return;
        }

        if (!inFence) prose.push({ line, text });
    });

    return prose;
}

/**
 * The note's headings.
 *
 * @param {string} markdown
 * @returns {Array<{level: number, text: string, line: number}>}
 */
export function outlineOf(markdown) {
    return proseLines(markdown)
        .map(({ line, text }) => {
            const match = HEADING.exec(text);

            return match
                ? { level: match[1].length, text: readable(match[2]), line }
                : null;
        })
        .filter((heading) => heading && "" !== heading.text);
}

/**
 * The number of words that are read: the prose, without the Markdown syntax
 * or the code.
 *
 * @param {string} markdown
 */
export function wordCount(markdown) {
    const text = proseLines(markdown)
        .map(({ text: one }) =>
            readable(
                one
                    .replace(/^\s*(#{1,6}|[-*+]|\d+[.)]|>)\s+/, "")
                    .replace(/^\s*\[[ xX]\]\s+/, ""),
            ),
        )
        .join(" ");

    return text.split(/\s+/).filter((word) => /[\p{L}\p{N}]/u.test(word))
        .length;
}

/** The reading time, in whole minutes, at least one as soon as there is a word. */
export function readingMinutes(words) {
    return words > 0 ? Math.max(1, Math.round(words / WORDS_PER_MINUTE)) : 0;
}
