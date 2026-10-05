/**
 * The colour of each row of a chart, read from and written into its data.
 *
 * The data stays the text the author types, « name ; value ; #colour » one
 * line per row: the swatches only rewrite the third cell of a line, so what
 * was typed by hand and what was picked are the same thing.
 */
const SEPARATOR = /\s*[|;]\s*/;
const HEX = /^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i;

/** `#abc` as `#aabbcc`: the colour input only takes the long form. */
function longHex(color) {
    const hex = color.toLowerCase();

    return 4 === hex.length
        ? `#${hex[1]}${hex[1]}${hex[2]}${hex[2]}${hex[3]}${hex[3]}`
        : hex;
}

/** The rows that can carry a colour: every line that has a name. */
export function chartRows(code) {
    return String(code ?? "")
        .split("\n")
        .map((line, index) => {
            const cells = line.trim().split(SEPARATOR);
            const color = cells[2] ?? "";

            return {
                index,
                label: cells[0] ?? "",
                color: HEX.test(color) ? longHex(color) : null,
            };
        })
        .filter((row) => "" !== row.label);
}

/** The data with line `index` coloured, or back to the theme's shades when `color` is null. */
export function setChartColor(code, index, color) {
    const lines = String(code ?? "").split("\n");
    const cells = (lines[index] ?? "").trim().split(SEPARATOR);

    cells[1] ??= "";
    cells.length = Math.max(2, cells.length);
    if (null === color) {
        cells.splice(2, 1);
    } else {
        cells[2] = color.toLowerCase();
    }
    lines[index] = cells.join(" ; ");

    return lines.join("\n");
}
