import { compareSiblings } from "./noteSiblingOrder.js";

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

/** L'espace d'une ligne, `null` quand la liste ne le dit pas. */
function spaceOf(item) {
    return null == item?.spaceId ? null : Number(item.spaceId);
}

/**
 * Les frères d'un dossier, dossiers et notes mêlés, dans l'ordre affiché.
 *
 * Un seul ordre pour les deux natures : la position, à égalité le dossier
 * d'abord, puis le plus ancien (la règle de `compareSiblings`). À la racine,
 * seulement ceux du même espace : chaque espace a la sienne, et compter
 * ensemble les racines de deux espaces mélangerait deux ordres.
 *
 * @returns {Array<{kind: string, id: number}>}
 */
function siblings(folders, notes, parentId, spaceId = null) {
    const inGroup = (one, kind) =>
        parentOf(one, kind) === parentId &&
        (null !== parentId || null === spaceId || spaceOf(one) === spaceId);

    return [
        ...folders
            .filter((one) => inGroup(one, "folder"))
            .map((one) => ({
                kind: "folder",
                id: Number(one.id),
                position: one.position,
            })),
        ...notes
            .filter((one) => inGroup(one, "note"))
            .map((one) => ({
                kind: "note",
                id: Number(one.id),
                position: one.position,
            })),
    ]
        .sort(compareSiblings)
        .map(({ kind, id }) => ({ kind, id }));
}

const sameEntry = (a, b) => a.kind === b.kind && a.id === b.id;

/**
 * Ce qu'un dépôt demande d'écrire, ou `null` quand il ne peut pas aboutir.
 *
 * Le résultat dit **où** ranger (`folderId`, `null` pour la racine) et
 * **dans quel ordre** laisser les frères (`order`, des `{kind, id}`), dossiers
 * et notes mêlés : depuis qu'ils partagent un ordre, une note se lâche avant
 * un dossier et y reste.
 *
 * @param {object} args
 * @param {{kind: string, id: number}} args.dragged      ce qu'on tient
 * @param {{kind: string, id: number|null, spaceId?: number}} args.target
 *        la ligne visée ; `{kind: "folder", id: null}` est la racine, celle
 *        de l'espace `spaceId` quand il est dit, sinon celle où l'on est
 * @param {"before"|"inside"|"after"} args.zone
 * @param {Array} args.folders
 * @param {Array} args.notes
 */
export function planDrop({ dragged, target, zone, folders, notes }) {
    if (!dragged || !target) return null;

    const kind = dragged.kind;
    const id = Number(dragged.id);
    const moving = ("folder" === kind ? folders : notes).find(
        (one) => Number(one.id) === id,
    );

    if (!moving) return null;

    // La racine, ou le milieu d'un dossier : on range dedans, en dernier.
    const intoFolder =
        null === target.id || ("folder" === target.kind && "inside" === zone);

    let folderId;
    let spaceId;
    let anchor = null;
    let after = true;

    if (intoFolder) {
        folderId = null === target.id ? null : Number(target.id);
        const folder =
            null === folderId
                ? null
                : folders.find((one) => Number(one.id) === folderId);
        spaceId =
            null === target.id
                ? null == target.spaceId
                    ? spaceOf(moving)
                    : Number(target.spaceId)
                : spaceOf(folder);
    } else {
        const list = "folder" === target.kind ? folders : notes;
        const row = list.find((one) => Number(one.id) === Number(target.id));

        if (!row) return null;

        folderId = parentOf(row, target.kind);
        spaceId = spaceOf(row);
        anchor = { kind: target.kind, id: Number(target.id) };
        after = "after" === zone;
    }

    // Un dossier ne se range ni en lui-même ni dans ce qu'il contient.
    if (
        "folder" === kind &&
        null !== folderId &&
        isWithin(folders, id, folderId)
    ) {
        return null;
    }

    const self = { kind, id };
    if (null !== anchor && sameEntry(anchor, self)) return null;

    const before = siblings(folders, notes, folderId, spaceId);
    const order = before.filter((one) => !sameEntry(one, self));

    if (null !== anchor) {
        const at = order.findIndex((one) => sameEntry(one, anchor));
        order.splice(after ? at + 1 : at, 0, self);
    } else {
        order.push(self);
    }

    const fromFolderId = parentOf(moving, kind);
    const fromSpaceId = spaceOf(moving);
    const key = (list) => list.map((one) => `${one.kind}:${one.id}`).join(",");

    // Rien ne bouge : même dossier, même espace, même rang. Pas d'appel, pas
    // de message.
    if (
        fromFolderId === folderId &&
        fromSpaceId === spaceId &&
        key(before) === key(order)
    )
        return null;

    return { kind, id, folderId, fromFolderId, spaceId, fromSpaceId, order };
}
