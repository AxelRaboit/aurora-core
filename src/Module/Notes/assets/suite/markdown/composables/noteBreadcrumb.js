/**
 * A folder's path from the root, the highest first.
 *
 * It is what the breadcrumb writes above an open note, and what the creation
 * modal says of the place where it files. The loop counts its turns: a
 * damaged notebook where a folder designated itself must give a short path,
 * not a frozen page.
 *
 * @param {Array} folders           the person's folders, flat
 * @param {number|null} folderId    the target folder; `null` = the root
 * @returns {Array<{id: number, name: string, color: ?string}>}
 */
export function folderPath(folders, folderId) {
    if (null == folderId) return [];

    const byId = new Map(folders.map((folder) => [Number(folder.id), folder]));
    const path = [];
    const seen = new Set();

    let current = byId.get(Number(folderId));

    while (current && !seen.has(Number(current.id))) {
        seen.add(Number(current.id));
        path.unshift({
            id: Number(current.id),
            name: current.name ?? "",
            color: current.color ?? null,
        });

        current =
            null == current.parentId
                ? null
                : byId.get(Number(current.parentId));
    }

    return path;
}
