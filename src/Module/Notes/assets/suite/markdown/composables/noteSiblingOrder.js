/**
 * The order of one level of the notes tree.
 *
 * Folders and notes of the same folder share a single order since they can
 * be mixed: the position first, then, on a tie, the folder before the note,
 * then the oldest. It is the server's rule for the reading order
 * (`MarkdownNotesViewBuilder::readingOrder`).
 *
 * @param {{kind: string, id: number, position?: number}} a
 * @param {{kind: string, id: number, position?: number}} b
 */
export function compareSiblings(a, b) {
    return (
        (a.position ?? 0) - (b.position ?? 0) ||
        ("folder" === a.kind ? 0 : 1) - ("folder" === b.kind ? 0 : 1) ||
        Number(a.id) - Number(b.id)
    );
}
