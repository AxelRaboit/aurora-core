/**
 * Ce que les autres ont partagé, regroupé par dossier partagé d'origine.
 *
 * Le serveur rend les dossiers partagés **et** leurs descendants, et toutes
 * les notes qu'on peut y lire, à n'importe quelle profondeur. Un écran n'en
 * montre que les racines : chaque note est rattachée à la sienne, avec le nom
 * de son sous-dossier pour qu'on sache d'où elle vient. Les notes partagées
 * seules, hors de tout dossier partagé, ferment la liste.
 *
 * Le panneau du menu et le lecteur s'en servent tous deux : deux copies de ce
 * regroupement auraient fini par ne plus ranger pareil.
 *
 * @param {Array<{id: number, parentId: ?number, name: string, ownerName?: string}>} folders
 * @param {Array<{id: number, folderId: ?number}>} notes
 * @returns {Array<{key: string, name: ?string, owner: ?string, notes: Array}>}
 */
export function groupShared(folders, notes) {
    const byId = new Map(folders.map((one) => [Number(one.id), one]));

    // La racine partagée d'un dossier, en remontant ses parents. La boucle
    // compte ses tours : un parent qui se désignerait lui-même ne doit pas
    // figer la page.
    const rootOf = (folderId) => {
        let current = byId.get(Number(folderId));

        for (let guard = 0; current && guard <= byId.size; guard += 1) {
            if (!byId.has(Number(current.parentId))) return Number(current.id);

            current = byId.get(Number(current.parentId));
        }

        return null;
    };

    const byRoot = new Map();
    const loose = [];

    for (const note of notes) {
        const root = null == note.folderId ? null : rootOf(note.folderId);

        if (null === root) {
            loose.push(note);

            continue;
        }

        const subfolder =
            Number(note.folderId) === root
                ? null
                : (byId.get(Number(note.folderId))?.name ?? null);

        if (!byRoot.has(root)) byRoot.set(root, []);

        byRoot.get(root).push({ ...note, subfolder });
    }

    const groups = folders
        .filter((one) => !byId.has(Number(one.parentId)))
        .map((root) => ({
            key: `folder:${root.id}`,
            name: root.name,
            owner: root.ownerName ?? null,
            notes: byRoot.get(Number(root.id)) ?? [],
        }));

    return loose.length
        ? [...groups, { key: "loose", name: null, owner: null, notes: loose }]
        : groups;
}
