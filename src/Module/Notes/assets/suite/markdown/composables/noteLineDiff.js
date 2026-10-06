/**
 * What changes between two texts, line by line.
 *
 * For a note's history: a version is compared with the current state by
 * showing the removed and added lines, like a "git diff" without its
 * headers. A note is text, and that is what one wants to see first: what
 * moved, not two columns to reread side by side.
 *
 * Longest common subsequence, in O(n × m). Beyond `MAX_CELLS` (two notes of
 * two thousand lines), the computation stops and returns `null`: the screen
 * then shows the whole version rather than freezing the page.
 */
const MAX_CELLS = 4_000_000;

/**
 * @param {string} before the version's text
 * @param {string} after  the current text
 * @returns {Array<{kind: "same"|"removed"|"added", text: string}>|null}
 */
export function lineDiff(before, after) {
    const a = String(before ?? "").split("\n");
    const b = String(after ?? "").split("\n");

    if (a.length * b.length > MAX_CELLS) return null;

    // lengths[i][j]: the longest common subsequence of a[i..] and b[j..].
    const lengths = Array.from(
        { length: a.length + 1 },
        () => new Uint32Array(b.length + 1),
    );
    for (let i = a.length - 1; i >= 0; i -= 1) {
        for (let j = b.length - 1; j >= 0; j -= 1) {
            lengths[i][j] =
                a[i] === b[j]
                    ? lengths[i + 1][j + 1] + 1
                    : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
        }
    }

    const lines = [];
    let i = 0;
    let j = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            lines.push({ kind: "same", text: a[i] });
            i += 1;
            j += 1;
        } else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
            lines.push({ kind: "removed", text: a[i] });
            i += 1;
        } else {
            lines.push({ kind: "added", text: b[j] });
            j += 1;
        }
    }
    while (i < a.length) lines.push({ kind: "removed", text: a[i++] });
    while (j < b.length) lines.push({ kind: "added", text: b[j++] });

    return lines;
}

/** How many lines were added and removed. */
export function diffStats(lines) {
    return (lines ?? []).reduce(
        (stats, line) => ({
            added: stats.added + ("added" === line.kind ? 1 : 0),
            removed: stats.removed + ("removed" === line.kind ? 1 : 0),
        }),
        { added: 0, removed: 0 },
    );
}
