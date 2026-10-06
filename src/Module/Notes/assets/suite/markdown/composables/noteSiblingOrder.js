/**
 * The order of one level of the notes tree.
 *
 * Folders and notes of the same folder share a single order since they can
 * be mixed: the position first, then, on a tie, the folder before the note,
 * then the oldest. It is the server's rule for the reading order
 * (`MarkdownNotesViewBuilder::readingOrder`).
 *
 * @param {{kind: string, id: number, position?: number}} left
 * @param {{kind: string, id: number, position?: number}} right
 */
export function compareSiblings(left, right) {
    return (
        (left.position ?? 0) - (right.position ?? 0) ||
        ("folder" === left.kind ? 0 : 1) - ("folder" === right.kind ? 0 : 1) ||
        Number(left.id) - Number(right.id)
    );
}
