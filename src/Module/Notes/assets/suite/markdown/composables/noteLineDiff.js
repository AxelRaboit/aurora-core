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
    const beforeLines = String(before ?? "").split("\n");
    const afterLines = String(after ?? "").split("\n");

    if (beforeLines.length * afterLines.length > MAX_CELLS) return null;

    // lengths[beforeIndex][afterIndex]: the longest common subsequence of
    // beforeLines[beforeIndex..] and afterLines[afterIndex..].
    const lengths = Array.from(
        { length: beforeLines.length + 1 },
        () => new Uint32Array(afterLines.length + 1),
    );
    for (
        let beforeIndex = beforeLines.length - 1;
        beforeIndex >= 0;
        beforeIndex -= 1
    ) {
        for (
            let afterIndex = afterLines.length - 1;
            afterIndex >= 0;
            afterIndex -= 1
        ) {
            lengths[beforeIndex][afterIndex] =
                beforeLines[beforeIndex] === afterLines[afterIndex]
                    ? lengths[beforeIndex + 1][afterIndex + 1] + 1
                    : Math.max(
                          lengths[beforeIndex + 1][afterIndex],
                          lengths[beforeIndex][afterIndex + 1],
                      );
        }
    }

    const lines = [];
    let beforeIndex = 0;
    let afterIndex = 0;
    while (beforeIndex < beforeLines.length && afterIndex < afterLines.length) {
        if (beforeLines[beforeIndex] === afterLines[afterIndex]) {
            lines.push({ kind: "same", text: beforeLines[beforeIndex] });
            beforeIndex += 1;
            afterIndex += 1;
        } else if (
            lengths[beforeIndex + 1][afterIndex] >=
            lengths[beforeIndex][afterIndex + 1]
        ) {
            lines.push({ kind: "removed", text: beforeLines[beforeIndex] });
            beforeIndex += 1;
        } else {
            lines.push({ kind: "added", text: afterLines[afterIndex] });
            afterIndex += 1;
        }
    }
    while (beforeIndex < beforeLines.length)
        lines.push({ kind: "removed", text: beforeLines[beforeIndex++] });
    while (afterIndex < afterLines.length)
        lines.push({ kind: "added", text: afterLines[afterIndex++] });

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
