/**
 * The rows of a chart, read from and written back to its data.
 *
 * The data stays the text the server reads, « name ; value ; #colour » one
 * line per row: the table only edits it cell by cell, so a chart typed by
 * hand, pasted from a spreadsheet or built row by row is the same thing.
 */
const SEPARATOR = /\s*[|;]\s*/;
const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

/** The rows the server draws: past that, lines are ignored. */
export const MAX_CHART_ROWS = 24;

/** `#abc` as `#aabbcc`: the colour input only takes the long form. */
function longHex(color) {
    const hex = color.toLowerCase();

    return 4 === hex.length
        ? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`
        : hex;
}

/** One row per line that says something; a third cell that is not a colour is dropped, as the page drops it. */
export function parseChartRows(code) {
    return String(code ?? "")
        .split("\n")
        .map((line) => line.trim())
        .filter((line) => "" !== line)
        .map((line) => {
            const [label = "", value = "", color = ""] = line.split(SEPARATOR);

            return {
                label,
                value,
                color: HEX.test(color) ? longHex(color) : null,
            };
        });
}

/** The data for these rows; a row left blank is not written, so a new line can wait to be filled. */
export function formatChartRows(rows) {
    return rows
        .map((row) => ({
            label: row.label.trim(),
            value: row.value.trim(),
            color: row.color,
        }))
        .filter((row) => "" !== row.label || "" !== row.value)
        .map((row) => {
            if (row.color) {
                return `${row.label} ; ${row.value} ; ${row.color}`;
            }

            return "" === row.value ? row.label : `${row.label} ; ${row.value}`;
        })
        .join("\n");
}
