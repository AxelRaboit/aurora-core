import { compareSiblings } from "./noteSiblingOrder.js";

/**
 * Where what is dropped in the tree lands, and what it changes.
 *
 * Craft, Notion and Obsidian all read the same thing in the pointer's
 * position on a row: the top means "before", the bottom "after", and the
 * middle of a folder "inside". A note contains nothing, so it only has two
 * halves.
 *
 * The computation is here, away from the component, for two reasons: it is
 * tested without a browser, and both the panel and the page need it - one to
 * light the right row during the drag, the other to write the result.
 */

/** The share of a folder's height that counts as "before" or "after". */
const EDGE = 0.25;

/**
 * The zone targeted on a row.
 *
 * @param {{top: number, height: number}} rect the row, in screen pixels
 * @param {number} clientY                       the pointer
 * @param {"folder"|"note"} kind                 what the row is
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
 * Does one folder hold the other, at any depth?
 *
 * True also for a folder and itself: in both cases, dropping it there would
 * close a loop. The loop counts its turns, because a damaged notebook where
 * a parent designated itself must not freeze the page.
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

/** The folder that holds a row, `null` for the root. */
function parentOf(item, kind) {
    const raw = "folder" === kind ? item.parentId : item.folderId;

    return null == raw ? null : Number(raw);
}

/** A row's space, `null` when the list does not say. */
function spaceOf(item) {
    return null == item?.spaceId ? null : Number(item.spaceId);
}

/**
 * A folder's siblings, folders and notes mixed, in the displayed order.
 *
 * A single order for both kinds: the position, on a tie the folder first,
 * then the oldest (the rule of `compareSiblings`). At the root, only those
 * of the same space: each space has its own, and counting the roots of two
 * spaces together would mix two orders.
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
 * What a drop asks to write, or `null` when it cannot succeed.
 *
 * The result says **where** to file (`folderId`, `null` for the root) and
 * **in which order** to leave the siblings (`order`, `{kind, id}` items),
 * folders and notes mixed: since they share an order, a note dropped before
 * a folder stays there.
 *
 * @param {object} args
 * @param {{kind: string, id: number}} args.dragged      what is held
 * @param {{kind: string, id: number|null, spaceId?: number}} args.target
 *        the targeted row; `{kind: "folder", id: null}` is the root, the one
 *        of the `spaceId` space when given, otherwise the one we are in
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

    // The root, or the middle of a folder: we file inside, last.
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

    // A folder is filed neither into itself nor into what it holds.
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

    // Nothing moves: same folder, same space, same rank. No call, no
    // message.
    if (
        fromFolderId === folderId &&
        fromSpaceId === spaceId &&
        key(before) === key(order)
    )
        return null;

    return { kind, id, folderId, fromFolderId, spaceId, fromSpaceId, order };
}
