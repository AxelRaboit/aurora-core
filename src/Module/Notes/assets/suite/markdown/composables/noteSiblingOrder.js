/**
 * L'ordre d'un niveau de l'arborescence des notes.
 *
 * Dossiers et notes d'un même dossier partagent un seul ordre depuis qu'on
 * peut les mêler : la position d'abord, puis, à égalité, le dossier avant la
 * note, puis le plus ancien. C'est la règle du serveur pour l'ordre de
 * lecture (`MarkdownNotesViewBuilder::readingOrder`).
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
