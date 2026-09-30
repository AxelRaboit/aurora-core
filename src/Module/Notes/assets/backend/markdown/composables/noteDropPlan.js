/**
 * Où tombe ce qu'on lâche dans l'arborescence, et ce que ça change.
 *
 * Craft, Notion et Obsidian lisent tous la même chose dans la position du
 * pointeur sur une ligne : le haut veut dire « avant », le bas « après », et le
 * milieu d'un dossier « dedans ». Une note ne contient rien, donc elle n'a que
 * deux moitiés.
 *
 * Le calcul est ici, loin du composant, pour deux raisons : il se teste sans
 * navigateur, et le panneau comme la page en ont besoin - l'un pour allumer la
 * bonne ligne pendant le glisser, l'autre pour écrire le résultat.
 */

/** La part de la hauteur d'un dossier qui vaut « avant » ou « après ». */
const EDGE = 0.25;

/**
 * La zone visée sur une ligne.
 *
 * @param {{top: number, height: number}} rect la ligne, en pixels écran
 * @param {number} clientY                       le pointeur
 * @param {"folder"|"note"} kind                 ce qu'est la ligne
 * @returns {"before"|"inside"|"after"}
 */
export function dropZone(rect, clientY, kind) {
    const ratio = rect.height > 0 ? (clientY - rect.top) / rect.height : 0.5;

    if ("folder" !== kind) return ratio < 0.5 ? "before" : "after";
    if (ratio < EDGE) return "before";
    if (ratio > 1 - EDGE) return "after";

    return "inside";
}

/**
 * Un dossier range-t-il l'autre, à n'importe quelle profondeur ?
 *
 * Vrai aussi pour un dossier et lui-même : dans les deux cas, le déposer là
 * fermerait une boucle. La boucle compte ses tours, parce qu'un carnet abîmé
 * dont un parent se désignerait lui-même ne doit pas figer la page.
 */
export function isWithin(folders, ancestorId, folderId) {
    if (null === ancestorId || null === folderId) return false;

    const parents = new Map(
        folders.map((f) => [
            Number(f.id),
            null == f.parentId ? null : Number(f.parentId),
        ]),
    );

    let current = Number(folderId);

    for (let guard = 0; null !== current && guard <= parents.size; guard += 1) {
        if (current === Number(ancestorId)) return true;

        current = parents.get(current) ?? null;
    }

    return false;
}

/** Le dossier qui porte une ligne, `null` pour la racine. */
function parentOf(item, kind) {
    const raw = "folder" === kind ? item.parentId : item.folderId;

    return null == raw ? null : Number(raw);
}

/** Les frères d'une même nature dans un dossier, dans l'ordre affiché. */
function siblings(items, kind, parentId) {
    return items
        .filter((one) => parentOf(one, kind) === parentId)
        .sort(
            (a, b) =>
                (a.position ?? 0) - (b.position ?? 0) ||
                Number(a.id) - Number(b.id),
        );
}

/**
 * Ce qu'un dépôt demande d'écrire, ou `null` quand il ne peut pas aboutir.
 *
 * Le résultat dit **où** ranger (`folderId`, `null` pour la racine) et
 * **dans quel ordre** laisser les frères de même nature (`order`, des
 * identifiants). Les dossiers passent toujours avant les notes à l'écran,
 * donc glisser une note « avant » un dossier la range en tête des notes de ce
 * niveau, et un dossier « après » une note en queue des dossiers : l'écran ne
 * sait pas montrer autre chose, et le promettre serait mentir.
 *
 * @param {object} args
 * @param {{kind: string, id: number}} args.dragged      ce qu'on tient
 * @param {{kind: string, id: number|null}} args.target  la ligne visée ;
 *        `{kind: "folder", id: null}` est la racine
 * @param {"before"|"inside"|"after"} args.zone
 * @param {Array} args.folders
 * @param {Array} args.notes
 */
export function planDrop({ dragged, target, zone, folders, notes }) {
    if (!dragged || !target) return null;

    const kind = dragged.kind;
    const id = Number(dragged.id);
    const items = "folder" === kind ? folders : notes;
    const moving = items.find((one) => Number(one.id) === id);

    if (!moving) return null;

    // La racine, ou le milieu d'un dossier : on range dedans, en dernier.
    const intoFolder =
        null === target.id || ("folder" === target.kind && "inside" === zone);

    let folderId;
    let anchorId = null;
    let after = true;

    if (intoFolder) {
        folderId = null === target.id ? null : Number(target.id);
    } else {
        const list = "folder" === target.kind ? folders : notes;
        const row = list.find((one) => Number(one.id) === Number(target.id));

        if (!row) return null;

        folderId = parentOf(row, target.kind);

        // Même nature : l'ordre suit exactement la ligne visée. Nature
        // différente : on tombe au bord du groupe, le seul endroit qui existe
        // à l'écran.
        if (target.kind === kind) {
            anchorId = Number(target.id);
            after = "after" === zone;
        } else {
            // Une note près d'un dossier : en tête des notes, juste sous les
            // dossiers. Un dossier près d'une note : en queue des dossiers,
            // juste au-dessus des notes.
            after = "note" === target.kind;
        }
    }

    // Un dossier ne se range ni en lui-même ni dans ce qu'il contient.
    if (
        "folder" === kind &&
        null !== folderId &&
        isWithin(folders, id, folderId)
    ) {
        return null;
    }

    const order = siblings(items, kind, folderId)
        .map((one) => Number(one.id))
        .filter((one) => one !== id);

    if (null !== anchorId) {
        if (anchorId === id) return null;

        const at = order.indexOf(anchorId);
        order.splice(after ? at + 1 : at, 0, id);
    } else if (after) {
        order.push(id);
    } else {
        order.unshift(id);
    }

    const fromFolderId = parentOf(moving, kind);
    const before = siblings(items, kind, folderId).map((one) => Number(one.id));

    // Rien ne bouge : même dossier, même rang. Pas d'appel, pas de message.
    if (fromFolderId === folderId && before.join(",") === order.join(","))
        return null;

    return { kind, id, folderId, fromFolderId, order };
}
