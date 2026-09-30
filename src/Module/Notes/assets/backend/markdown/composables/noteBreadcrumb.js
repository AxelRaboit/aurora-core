/**
 * Le chemin d'un dossier depuis la racine, le plus haut d'abord.
 *
 * C'est ce qu'écrit le fil d'Ariane au-dessus d'une note ouverte, et ce que la
 * modale de création dit de l'endroit où elle range. La boucle compte ses
 * tours : un carnet abîmé dont un dossier se désignerait lui-même doit donner
 * un chemin court, pas une page figée.
 *
 * @param {Array} folders           les dossiers de la personne, à plat
 * @param {number|null} folderId    le dossier d'arrivée ; `null` = la racine
 * @returns {Array<{id: number, name: string, color: ?string}>}
 */
export function folderPath(folders, folderId) {
    if (null == folderId) return [];

    const byId = new Map(folders.map((f) => [Number(f.id), f]));
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
