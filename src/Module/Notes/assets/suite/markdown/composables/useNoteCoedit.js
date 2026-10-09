import { onBeforeUnmount, watch } from "vue";
import * as Y from "yjs";
import {
    JOIN_SEED,
    answersDocRequest,
    isElected,
    isForMe,
    isMine,
    joinAction,
    textDelta,
} from "./noteCoeditProtocol.js";

/**
 * A note written by several people at once, letter by letter.
 *
 * **No service.** The clients hold the document - each one whole, which is
 * what a CRDT gives you - and the bus is only the rendezvous. The first client
 * into an empty room seeds the document from the stored markdown and becomes
 * the origin; anybody arriving after asks a peer for the state and never
 * seeds, because two documents built independently from the same text have
 * two different histories and merging those duplicates every character.
 *
 * **Nothing is persisted outside Postgres.** The elected client writes the
 * derived markdown back through the ordinary save route on a debounce, so the
 * excerpt, the search and the public page never fall more than a few seconds
 * behind - and the note is the record, the document being a session artifact
 * that can be dropped at any time without losing anything.
 *
 * **It refuses rather than guesses.** No hub, no permission, a space that does
 * not allow it, or a peer that never answers: the editor stays on the autosave
 * and the three-way merge, which is a mode that works. Half a co-editing
 * session would be the one outcome worse than not having one.
 *
 * @param {object} options
 * @param {import("vue").Ref<number|null>} options.noteId
 * @param {import("vue").Ref<boolean>}     options.allowed     the four conditions, decided outside
 * @param {import("vue").Ref<boolean>}     options.live        written here, read by the editor: it has
 *                                                             to exist before the editor is built, since
 *                                                             the editor's autosave is suspended by it
 * @param {import("vue").Ref<string>}      options.text        the form's body
 * @param {Function}                       options.applyText   writes the body back into the form
 * @param {import("vue").Ref<Array>}       options.room        who else is here
 * @param {object}                         options.channel     publish/subscribe over the bus
 * @param {Function}                       options.writeBack   persists the markdown
 */
export function useNoteCoedit({
    noteId,
    allowed,
    text,
    applyText,
    room,
    channel,
    writeBack,
    live,
}) {
    /** How long to wait for a peer to answer before asking again. */
    const STATE_TIMEOUT_MS = 4000;

    /**
     * How many times a newcomer asks for the state before standing down.
     *
     * More than one, because the first attempt can legitimately find nobody
     * able to answer: the peer re-enters its own session whenever the note or
     * the space setting changes, and for those few hundred milliseconds it
     * holds no document to send. A newcomer that asked once and gave up
     * stayed dead for as long as the note was open - the editor fell back to
     * the autosave, which works, so the only sign was that two people typing
     * never saw each other. It cost an afternoon to find.
     */
    const JOIN_ATTEMPTS = 3;

    /** How long after the last keystroke the elected client writes back. */
    const WRITE_BACK_MS = 3000;

    let sharedDocument = null;
    let body = null;
    let joining = null;
    let joinAttempts = 0;
    let writeTimer = null;
    // Set while a remote update is being applied, so the observer that
    // publishes local edits does not publish them straight back.
    let applying = false;

    /**
     * How long a client that holds the document, without being the one
     * designated to answer, waits before answering a newcomer in its stead.
     *
     * The designated client can be gone: a closed tab sends nothing, and the
     * room keeps it for fifty seconds. A newcomer arriving in that window
     * asked, nobody answered, and it stood down - seen with a guest arriving
     * just after an account left (09/10/2026). So the other holders answer
     * too, a moment later and only if nobody has. Answering twice is harmless:
     * everybody in the session holds the same history, and applying it again
     * changes nothing.
     */
    const BACKUP_ANSWER_MS = 1500;

    /** Whether this client was the writer at the last look at the room. */
    let wasElected = false;

    /** Newcomer id -> the backup answer this client will send if nobody does. */
    const backupAnswers = new Map();

    function answerWithState(requester) {
        channel.publish({
            kind: "doc-state",
            to: requester,
            state: toBase64(Y.encodeStateAsUpdate(sharedDocument)),
        });
    }

    function scheduleBackupAnswer(requester) {
        const key = Number(requester);
        if (backupAnswers.has(key)) return;

        backupAnswers.set(
            key,
            setTimeout(() => {
                backupAnswers.delete(key);
                if (sharedDocument) answerWithState(key);
            }, BACKUP_ANSWER_MS),
        );
    }

    function cancelBackupAnswer(requester) {
        const key = Number(requester);
        const timer = backupAnswers.get(key);
        if (!timer) return;

        clearTimeout(timer);
        backupAnswers.delete(key);
    }

    function teardown() {
        for (const timer of backupAnswers.values()) clearTimeout(timer);
        backupAnswers.clear();
        if (writeTimer) clearTimeout(writeTimer);
        writeTimer = null;
        if (joining) clearTimeout(joining);
        joining = null;
        if (sharedDocument) sharedDocument.destroy();
        sharedDocument = null;
        body = null;
        live.value = false;
    }

    /**
     * Builds the document and starts listening to it.
     *
     * The observer publishes every local change as an update and nothing else:
     * a CRDT update is the difference, so there is no diffing to do and no
     * version to agree on.
     */
    function openSharedDocument(seedText) {
        sharedDocument = new Y.Doc();
        body = sharedDocument.getText("body");

        if (null !== seedText) body.insert(0, seedText);

        body.observe(() => {
            const value = body.toString();

            // Into the form first: what is on screen is what the document
            // says, always, or the two drift and the next write-back persists
            // the wrong one.
            if (value !== text.value) applyText(value);

            if (!applying) scheduleWriteBack();
        });

        sharedDocument.on("update", (update, origin) => {
            // Updates that came off the bus are not republished: everybody
            // already has them, and an echo would loop.
            if ("remote" === origin) return;

            channel.publish({ kind: "doc-update", update: toBase64(update) });
            scheduleWriteBack();
        });

        live.value = true;
    }

    /**
     * Writes the markdown back, once the typing stops.
     *
     * **Only the elected client.** Everybody holds the same text, so everybody
     * writing it would be the same save sent several times, each refused by
     * the version check for a conflict that does not exist.
     */
    function scheduleWriteBack() {
        if (writeTimer) clearTimeout(writeTimer);

        writeTimer = setTimeout(() => {
            writeTimer = null;

            if (!live.value || !body) return;
            if (!isElected(channel.selfUserId(), room.value)) return;

            void writeBack(body.toString());
        }, WRITE_BACK_MS);
    }

    /** A message off the channel. */
    function onMessage(message) {
        const self = channel.selfUserId();

        // Somebody else already answered that newcomer: no backup answer is
        // owed any more. Read before the filter below, since the answer was
        // addressed to the newcomer and not to this client.
        if ("doc-state" === message.kind && !isMine(message, self)) {
            cancelBackupAnswer(message.to);
        }

        if (isMine(message, self) || !isForMe(message, self)) return;

        if ("doc-request" === message.kind) {
            // Answered by one client only, so a newcomer does not receive the
            // state once per person already in the room - and not by the
            // elected one, who may be the newcomer itself: see
            // `answersDocRequest`.
            if (!sharedDocument) return;

            if (answersDocRequest(self, room.value, message.from)) {
                answerWithState(message.from);
            } else {
                scheduleBackupAnswer(message.from);
            }

            return;
        }

        if ("doc-state" === message.kind) {
            if (joining) {
                clearTimeout(joining);
                joining = null;
            }

            // The peer's document becomes ours, history included - that is
            // what makes the two converge from here on.
            if (!sharedDocument) openSharedDocument(null);
            applyRemote(fromBase64(message.state));

            return;
        }

        if ("doc-update" === message.kind && sharedDocument) {
            applyRemote(fromBase64(message.update));
        }
    }

    function applyRemote(update) {
        applying = true;
        try {
            Y.applyUpdate(sharedDocument, update, "remote");
        } finally {
            applying = false;
        }
    }

    /** Enters the note, or declines. */
    function enter() {
        teardown();

        if (null == noteId.value || !allowed.value) return;

        if (JOIN_SEED === joinAction(room.value)) {
            openSharedDocument(text.value ?? "");

            return;
        }

        channel.publish({ kind: "doc-request" });

        joining = setTimeout(() => {
            joining = null;
            if (live.value) return;

            const self = channel.selfUserId();

            // **Nobody answered, so the elected client seeds.** This is what
            // breaks the tie, and without it the protocol deadlocked: two
            // people who both opened the note before either was allowed to
            // co-edit each saw the other in the room, each therefore asked
            // instead of seeding, and neither ever had a document to answer
            // with. It lasted as long as the note stayed open, the editor
            // quietly back on its autosave, and nothing on screen said so.
            //
            // Seeding from the stored text is safe here precisely because
            // asking came first: a peer that holds a document answers in
            // milliseconds, so reaching this line means there is no history
            // to lose. And only the elected client may do it - a second
            // client seeding would build a second history, and merging two
            // histories of the same text duplicates every character of it.
            if (isElected(self, room.value)) {
                openSharedDocument(text.value ?? "");

                return;
            }

            // Not elected: keep asking. The elected client is about to seed,
            // and the next round is what collects its state.
            if (joinAttempts < JOIN_ATTEMPTS) {
                joinAttempts += 1;
                enter();

                return;
            }

            // Asked three times and the elected client never answered. The
            // editor stays on the autosave, which works.
            teardown();
        }, STATE_TIMEOUT_MS);
    }

    /**
     * What somebody typed, as an operation on the document.
     *
     * **This is the direction the feature is made of, and it was missing.**
     * The textarea is bound to the form, not to the document: without this
     * watcher the document only ever flowed outwards, so a session started,
     * elected, seeded and wrote back - and carried not one keystroke. Nothing
     * without a browser could notice, because every piece in isolation was
     * right; the two-browser test is what found it.
     *
     * The no-op case carries its weight too: a remote update is applied into
     * the form, which lands here as a change, and the delta against the
     * document is then empty. That is what stops the echo, rather than a flag
     * that has to be held correctly across a transaction.
     */
    watch(text, (value) => {
        if (!sharedDocument || !body || null == value) return;

        const delta = textDelta(body.toString(), value);
        if (null === delta) return;

        // One transaction, so a replaced run travels as a single update
        // instead of a deletion the peers briefly render on its own.
        sharedDocument.transact(() => {
            if (0 < delta.remove) body.delete(delta.index, delta.remove);
            if ("" !== delta.insert) body.insert(delta.index, delta.insert);
        });
    });

    const stopListening = channel.onMessage(onMessage);

    // The open note changes, or the right to co-edit it does - a space whose
    // setting was just switched, a hub that came back.
    watch([noteId, allowed], () => {
        joinAttempts = 0;
        enter();
    });

    /**
     * Somebody joined or left, and we are not in a session.
     *
     * The other half of the retry, and the half that matters: who is in the
     * room is what decides between seeding and asking, and that is the one
     * input to the decision that arrives *after* it was taken. Someone who
     * opened the note alone seeded and is the origin; someone who opened it
     * while the first was between two documents asked, heard nothing, and has
     * to be told that there is now somebody to ask again.
     *
     * Guarded on `live`, so a presence beat never interrupts a session that
     * is working - re-entering one would throw away a document that is
     * holding what people are typing.
     */
    watch(room, () => {
        if (!live.value) {
            enter();

            return;
        }

        // **Becoming the writer writes.** The writer is elected from the room,
        // and the room changes under a session: the one who was writing back
        // left - cleanly, or by a closed laptop the room forgets fifty seconds
        // later. What the others typed since was saved by nobody, and with
        // nothing more typed nothing would ever save it. So the client the
        // room now elects writes the text back at once.
        const elected = isElected(channel.selfUserId(), room.value);
        if (elected && !wasElected) scheduleWriteBack();
        wasElected = elected;
    });

    onBeforeUnmount(() => {
        // The last thing written is the text as it stands: a session ending
        // without a write-back would leave Postgres a debounce behind.
        if (live.value && body && isElected(channel.selfUserId(), room.value)) {
            void writeBack(body.toString());
        }

        stopListening();
        teardown();
    });

    return { enter };
}

/** Yjs speaks bytes; the bus carries text. */
function toBase64(bytes) {
    let binary = "";
    for (const byte of bytes) binary += String.fromCharCode(byte);

    return btoa(binary);
}

function fromBase64(value) {
    const binary = atob(value ?? "");
    const bytes = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}
