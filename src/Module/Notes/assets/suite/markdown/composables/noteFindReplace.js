import { findRanges, foldSearch } from "./noteSearchHighlight.js";

/**
 * Find and replace inside a note (10/10/2026), on the editor's text.
 *
 * By default the search ignores accents and case, as the notebook search
 * does: « echeance » finds « Échéance ». `exact` matches the text as typed.
 *
 * Offsets are in UTF-16 units, the ones a textarea selects with: an emoji
 * counts for two there, and a range in characters would select one letter
 * too early after it.
 */

/**
 * Every occurrence of `query` in `text`, in order, as `{start, end}`.
 *
 * @returns {Array<{start: number, end: number}>}
 */
export function findMatches(text, query, { exact = false } = {}) {
    const source = String(text ?? "");
    const wanted = String(query ?? "");
    if ("" === wanted) return [];

    if (exact) {
        const matches = [];
        for (
            let at = source.indexOf(wanted);
            at >= 0;
            at = source.indexOf(wanted, at + wanted.length)
        ) {
            matches.push({ start: at, end: at + wanted.length });
        }

        return matches;
    }

    const ranges = findRanges(source, [foldSearch(wanted)]);
    if (0 === ranges.length) return [];

    // Characters to UTF-16 units, once for the whole text.
    const offsets = [0];
    for (const character of source) {
        offsets.push(offsets.at(-1) + character.length);
    }

    return ranges.map(([start, length]) => ({
        start: offsets[start],
        end: offsets[start + length],
    }));
}

/** The match at or after `position`, going round to the first one. */
export function matchIndexFrom(matches, position) {
    if (0 === matches.length) return -1;
    const index = matches.findIndex((match) => match.start >= position);

    return -1 === index ? 0 : index;
}

/** The text with one match replaced. */
export function replaceOne(text, match, replacement) {
    return text.slice(0, match.start) + replacement + text.slice(match.end);
}

/** The text with every match replaced, from the last one back. */
export function replaceEvery(text, matches, replacement) {
    let result = text;
    for (const match of [...matches].reverse()) {
        result = replaceOne(result, match, replacement);
    }

    return result;
}
