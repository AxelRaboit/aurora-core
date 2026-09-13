/**
 * One line of a list slot, split into its cells.
 *
 * A card is a heading and a sentence, a step is a name and a date, a table row
 * is its cells: several values on one line, separated by a pipe. Split here
 * rather than on save, because the line is what somebody typed and storing the
 * pieces would mean a shape the editor has to put back together to let them
 * edit it again.
 *
 * Shared by the frame and by nothing else today, in its own file rather than
 * inside the component: the print page, the player and the public page all
 * draw through that one component, but a second reader of these lines is one
 * layout away.
 */
export const CELL_SEPARATOR = "|";

/**
 * The cells of one line, trimmed, empty ones kept.
 *
 * Kept because position is meaning in a table: "Avant | | Après" is a row whose
 * middle cell is empty, and dropping it would shift the third value under the
 * second column.
 */
export function cells(line) {
    return String(line ?? "")
        .split(CELL_SEPARATOR)
        .map((cell) => cell.trim());
}

/** A line as a heading and the rest, for a card or a step. */
export function headed(line) {
    const [head, ...rest] = cells(line);

    return { head, body: rest.join(` ${CELL_SEPARATOR} `) };
}
