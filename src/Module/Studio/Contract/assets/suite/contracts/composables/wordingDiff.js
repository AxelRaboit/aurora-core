/**
 * What an adapted wording changes, block by block, against the trame's.
 *
 * Read as lines of text rather than as JSON: what a reviewer needs before
 * sending is « this clause was added, that one struck », not a list of keys.
 * Each block becomes one line (a list one line per item, a table one per
 * row), markup stripped, and the two sequences are aligned on their longest
 * common run. A block edited in place shows as the old line struck and the
 * new one added, which is how a lawyer marks a draft.
 */

function plain(html) {
    return String(html ?? "")
        .replace(/<br\s*\/?>/gi, " ")
        .replace(/<[^>]*>/g, "")
        .replace(/&nbsp;/g, " ")
        .replace(/&amp;/g, "&")
        .replace(/&lt;/g, "<")
        .replace(/&gt;/g, ">")
        .replace(/&quot;/g, '"')
        .replace(/&#0?39;/g, "'")
        .replace(/\s+/g, " ")
        .trim();
}

function itemText(item) {
    return plain(typeof item === "string" ? item : item?.content);
}

/** The lines one block reads as. */
export function blockLines(block) {
    const data = block?.data ?? {};

    switch (block?.type) {
        case "list":
            return (data.items ?? []).map((item) => `• ${itemText(item)}`);
        case "table":
            return (data.content ?? []).map((row) =>
                (row ?? []).map(plain).join(" | "),
            );
        case "quote":
            return [plain(data.text)];
        default:
            return [plain(data.text)];
    }
}

/** Every line of a wording, the title first. */
export function wordingLines(title, blocks) {
    return [plain(title), ...(blocks ?? []).flatMap(blockLines)].filter(
        (line) => "" !== line,
    );
}

/**
 * The changes between two wordings.
 *
 * @returns {{ added: number, removed: number, changes: Array<{ type: "added"|"removed", text: string }> }}
 */
export function wordingDiff(original, adapted) {
    const a = wordingLines(original?.title, original?.blocks);
    const b = wordingLines(adapted?.title, adapted?.blocks);

    // Longest common subsequence, by table. Two hundred lines each is forty
    // thousand cells: nothing a browser notices.
    const table = Array.from({ length: a.length + 1 }, () =>
        new Array(b.length + 1).fill(0),
    );

    for (let i = a.length - 1; i >= 0; i -= 1) {
        for (let j = b.length - 1; j >= 0; j -= 1) {
            table[i][j] =
                a[i] === b[j]
                    ? table[i + 1][j + 1] + 1
                    : Math.max(table[i + 1][j], table[i][j + 1]);
        }
    }

    const changes = [];
    let i = 0;
    let j = 0;

    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            i += 1;
            j += 1;
        } else if (table[i + 1][j] >= table[i][j + 1]) {
            changes.push({ type: "removed", text: a[i] });
            i += 1;
        } else {
            changes.push({ type: "added", text: b[j] });
            j += 1;
        }
    }

    for (; i < a.length; i += 1) changes.push({ type: "removed", text: a[i] });
    for (; j < b.length; j += 1) changes.push({ type: "added", text: b[j] });

    return {
        added: changes.filter((change) => "added" === change.type).length,
        removed: changes.filter((change) => "removed" === change.type).length,
        changes,
    };
}
