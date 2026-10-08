import { onBeforeUnmount, watch } from "vue";
import * as Y from "yjs";
import {
    JOIN_SEED,
    isElected,
    isForMe,
    isMine,
    joinAction,
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
    /** How long to wait for a peer to answer before giving up on the session. */
    const STATE_TIMEOUT_MS = 4000;

    /** How long after the last keystroke the elected client writes back. */
    const WRITE_BACK_MS = 3000;

    let sharedDocument = null;
    let body = null;
    let joining = null;
    let writeTimer = null;
    // Set while a remote update is being applied, so the observer that
    // publishes local edits does not publish them straight back.
    let applying = false;

    function teardown() {
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

        if (isMine(message, self) || !isForMe(message, self)) return;

        if ("doc-request" === message.kind) {
            // Answered by one client only, so a newcomer does not receive the
            // state once per person already in the room.
            if (!sharedDocument || !isElected(self, room.value)) return;

            channel.publish({
                kind: "doc-state",
                to: message.from,
                state: toBase64(Y.encodeStateAsUpdate(sharedDocument)),
            });

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

        // A peer that never answers is a session that never starts. The
        // editor stays on the autosave rather than seeding a second history,
        // which would be the one failure that loses text.
        joining = setTimeout(() => {
            joining = null;
            if (!live.value) teardown();
        }, STATE_TIMEOUT_MS);
    }

    const stopListening = channel.onMessage(onMessage);

    // The open note changes, or the right to co-edit it does - a space whose
    // setting was just switched, a hub that came back.
    watch([noteId, allowed], () => enter());

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
