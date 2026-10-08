/**
 * Who does what in a co-editing session, decided without anybody coordinating.
 *
 * **Pure on purpose.** Everything here is a decision taken from the room as
 * the presence channel reports it - no document, no network, no clock. That is
 * what makes it testable, and this is the part where being wrong costs a
 * paragraph rather than a repaint.
 *
 * Two roles, both elected by the same rule: **the lowest account id in the
 * room**. It needs no agreement because every browser sees the same room and
 * applies the same comparison, and it survives somebody leaving - the next
 * lowest takes over on the next beat.
 *
 * - **The origin** seeds the document from the markdown. Exactly one client
 *   may ever do that, and only when the room is empty.
 * - **The writer** sends the derived markdown back to Aurora on a debounce.
 *   Exactly one, so the others do not race each other into a conflict.
 */

/** What a client should do when it enters a note. */
export const JOIN_SEED = "seed";
export const JOIN_REQUEST = "request";

/**
 * Seed the document, or ask a peer for it.
 *
 * **Never seed while somebody else is in the room**, and that is the whole
 * rule. Two clients that each build a document from the same markdown build
 * two different histories - the identities are generated, not derived from the
 * text - and merging those duplicates every character. So an arriving client
 * either finds an empty room and becomes the origin, or asks.
 *
 * @param {Array<{userId: number}>} room the others, as presence reports them
 */
export function joinAction(room) {
    return (room ?? []).length > 0 ? JOIN_REQUEST : JOIN_SEED;
}

/**
 * Whether this client is the one that answers a newcomer, and the one that
 * writes back.
 *
 * The lowest id among everybody present, this client included. A tie is
 * impossible: an account appears once in a room.
 *
 * @param {number} selfUserId
 * @param {Array<{userId: number}>} room the others
 */
export function isElected(selfUserId, room) {
    if (null == selfUserId) return false;

    const self = Number(selfUserId);

    return (room ?? []).every((peer) => Number(peer.userId) > self);
}

/**
 * Whether a message off the channel is addressed to this client.
 *
 * A state transfer names its recipient, because it is the one message that is
 * large and that only one client asked for: everybody receives it off the bus,
 * and everybody but its addressee drops it rather than applying a stranger's
 * document over their own.
 */
export function isForMe(message, selfUserId) {
    if (null == message?.to) return true;

    return Number(message.to) === Number(selfUserId);
}

/**
 * Whether a message came from this client.
 *
 * The bus echoes a publisher's own messages back to it. Applying one's own
 * update again is harmless in a CRDT - it is idempotent - but applying one's
 * own *state* is not free, and answering one's own request would be absurd.
 */
export function isMine(message, selfUserId) {
    return Number(message?.from) === Number(selfUserId);
}

/**
 * Whether co-editing can run at all, from what the server said.
 *
 * Four conditions, and the order is the one that reads: the space has to allow
 * it, this reader has to be able to write the note, a hub has to be running -
 * without one there is no channel and the editor stays on autosave - and the
 * page has to know who it is, since both elections compare account ids.
 */
export function canCoedit({ spaceAllows, canWrite, hasChannel, selfUserId }) {
    return (
        Boolean(spaceAllows) &&
        Boolean(canWrite) &&
        Boolean(hasChannel) &&
        null != selfUserId
    );
}

/**
 * What changed between two versions of the same text, as one operation.
 *
 * **Why not simply replace the whole text.** A textarea reports its value,
 * not the keystroke that produced it, so the difference has to be recovered
 * before it can become an operation on the document. Replacing everything
 * would read as "this person deleted the note and typed a new one", which a
 * CRDT would merge faithfully: a neighbour typing at the same moment would
 * lose their sentence, and both carets would jump to the end. The whole point
 * of the document is to carry small operations, so a small operation is what
 * it has to be handed.
 *
 * Common prefix, common suffix, and what sits between them - which for
 * somebody typing is exactly one inserted character, and for a paste or a
 * deletion is exactly the run that moved. Null when nothing changed, so the
 * echo of a remote update applied into the form stops here rather than going
 * back out as a local edit.
 *
 * @param {string} previous
 * @param {string} next
 * @returns {{index: number, remove: number, insert: string}|null}
 */
export function textDelta(previous, next) {
    if (previous === next) return null;

    const shortest = Math.min(previous.length, next.length);

    let start = 0;
    while (start < shortest && previous[start] === next[start]) start += 1;

    // Bounded by what the prefix left, so the two never overlap on a text
    // that repeats itself - "aa" becoming "a" is one deletion, not two.
    let end = 0;
    while (
        end < shortest - start &&
        previous[previous.length - 1 - end] === next[next.length - 1 - end]
    ) {
        end += 1;
    }

    return {
        index: start,
        remove: previous.length - start - end,
        insert: next.slice(start, next.length - end),
    };
}
