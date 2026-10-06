/**
 * Tab and Shift+Tab inside a Markdown table of the notes editor.
 *
 * Filling a table by hand meant putting the caret between two pipes, cell
 * after cell. Here Tab selects the next cell's content - typing replaces it,
 * as in a spreadsheet - and skips the `| --- |` line. Tab on the last cell of
 * the last row appends an empty row of the same width. Shift+Tab goes back
 * and stops on the first cell.
 *
 * Pure function on the text and the caret, so the textarea wiring only has to
 * apply the result (`useNoteEditorTextarea`). Returns null when the caret is
 * not on a table row: Tab then keeps its usual meaning.
 */

const ROW = /^\s*\|.*\|\s*$/;
const SEPARATOR = /^\s*\|(\s*:?-{3,}:?\s*\|)+\s*$/;

function splitLines(content) {
    const lines = [];
    let offset = 0;
    for (const text of content.split("\n")) {
        lines.push({ text, start: offset });
        offset += text.length + 1;
    }
    return lines;
}

/** Positions of the unescaped pipes of a row, relative to the line. */
function pipes(text) {
    const positions = [];
    for (let charIndex = 0; charIndex < text.length; charIndex++) {
        if (text[charIndex] === "|" && text[charIndex - 1] !== "\\")
            positions.push(charIndex);
    }
    return positions;
}

/**
 * The cells of a row as absolute ranges of their trimmed content. An empty
 * cell gives a zero-width range one space after its opening pipe, which is
 * where typing should land.
 */
function cells(line) {
    const bars = pipes(line.text);
    const result = [];
    for (let barIndex = 0; barIndex < bars.length - 1; barIndex++) {
        const inner = line.text.slice(bars[barIndex] + 1, bars[barIndex + 1]);
        const lead = inner.length - inner.trimStart().length;
        const content = inner.trim();
        const from =
            line.start +
            bars[barIndex] +
            1 +
            (content === "" ? Math.min(1, inner.length) : lead);
        result.push({ start: from, end: from + content.length });
    }
    return result;
}

const isRow = (line) =>
    line !== undefined && ROW.test(line.text) && !SEPARATOR.test(line.text);
const inTable = (line) => line !== undefined && ROW.test(line.text);

/**
 * @param {string} content the textarea value
 * @param {number} caret selectionStart
 * @param {boolean} backwards Shift+Tab
 * @returns {{ newContent: string, cursorPos: number, cursorEnd: number } | null}
 */
export function navigateTableCell(content, caret, backwards = false) {
    const lines = splitLines(content);
    const index = lines.findIndex(
        (line) => caret >= line.start && caret <= line.start + line.text.length,
    );
    const line = lines[index];
    if (!isRow(line)) return null;

    const own = cells(line);
    if (own.length === 0) return null;
    // The cell holding the caret: the last one that starts at or before it.
    let current = 0;
    own.forEach((cell, cellIndex) => {
        if (caret >= cell.start - 1) current = cellIndex;
    });

    const select = (cell, text = content) => ({
        newContent: text,
        cursorPos: cell.start,
        cursorEnd: cell.end,
    });

    if (!backwards) {
        if (current < own.length - 1) return select(own[current + 1]);
        for (
            let lineIndex = index + 1;
            inTable(lines[lineIndex]);
            lineIndex++
        ) {
            if (isRow(lines[lineIndex]))
                return select(cells(lines[lineIndex])[0]);
        }
        // Last cell of the table: a new empty row of the same width.
        const insertAt = line.start + line.text.length;
        const row = `\n| ${Array(own.length).fill("").join(" | ")} |`;
        const newContent =
            content.slice(0, insertAt) + row + content.slice(insertAt);
        const first = insertAt + 3;
        return { newContent, cursorPos: first, cursorEnd: first };
    }

    if (current > 0) return select(own[current - 1]);
    for (let lineIndex = index - 1; inTable(lines[lineIndex]); lineIndex--) {
        if (isRow(lines[lineIndex])) {
            const previous = cells(lines[lineIndex]);
            return select(previous[previous.length - 1]);
        }
    }
    return select(own[0]);
}
