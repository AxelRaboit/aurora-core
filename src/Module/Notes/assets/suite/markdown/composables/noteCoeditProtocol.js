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
