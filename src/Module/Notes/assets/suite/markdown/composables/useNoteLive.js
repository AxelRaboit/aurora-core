import { onBeforeUnmount, ref, watch } from "vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Who else is on the open note, and when it moves under you.
 *
 * **Three roads into one state, as the chat does it.** The beat answers with
 * the room and the note's version, the hub pushes the same two things as they
 * happen, and a reconnection beats again. All three write into `people` and
 * `serverVersion`, so the same news arriving twice is one piece of news.
 *
 * **Live is an improvement, not a requirement.** With a hub, a neighbour's
 * save shows at once. Without one - and most installations will never run one
 * - the beat every `beatSeconds` is what notices, which is the same twenty
 * seconds the space chat settled on. The page is told which it is by being
 * handed a stream address or null; it never has to guess.
 *
 * **It never decides anything about the text.** This composable says "the
 * server is at version N"; what to do about it belongs to the editor, which
 * is the only thing that knows whether anybody is mid-sentence. That line is
 * why a push cannot make somebody lose a paragraph.
 *
 * @param {object} options
 * @param {import("vue").Ref<number|null>} options.noteId   the open note, null for none
 * @param {import("vue").Ref<boolean>}     options.editing   editor open, or reader
 * @param {string}                         options.beatPath  `__id__` template
 */
export function useNoteLive({ noteId, editing, beatPath }) {
    const { request } = useRequest();

    /**
     * How long a cursor nobody refreshed stays drawn.
     *
     * Long enough that somebody thinking between two sentences does not
     * flicker out, short enough that a closed tab does not leave a ghost for
     * a minute.
     */
    const CURSOR_STALE_MS = 15000;

    /** The others on the note; never includes oneself. */
    const people = ref([]);

    /** The version the server last told us about, by either road. */
    const serverVersion = ref(null);

    /**
     * Where the others are in the text: `[{userId, name, index}]`.
     *
     * Self-reported, and that is not a weakness: a cursor is only ever known
     * to the browser it belongs to. Expired entries are dropped on read
     * rather than on a timer - a cursor nobody has refreshed is a cursor whose
     * owner stopped typing or closed the tab, and in both cases it should not
     * be drawn.
     */
    const cursors = ref([]);

    /**
     * Who made that last change, when the news came from a push.
     *
     * Null when it came from a beat, which answers with a version and not
     * with a name: "the note changed" is worth saying either way, and "Marie
     * changed it" only when we actually know.
     */
    const changedBy = ref(null);

    /** Whether a hub is carrying the news, or the beat is. */
    const live = ref(false);

    let source = null;
    let timer = null;
    let beatSeconds = 20;
    // Who this reader is, as the beat reports it: a pushed room carries
    // everybody, so this is what drops oneself from it.
    /**
     * Reactive, and it has to be.
     *
     * Both of these arrive with the first beat, which is *after* the page is
     * built - and something downstream decides whether to co-edit from them,
     * through a `computed`. Held as plain closure variables they changed
     * without telling anybody, so that computed evaluated once, saw nothing,
     * and never looked again: the session silently never started. Found by the
     * two-browser test, which is the only thing that could have found it.
     */
    const selfUserId = ref(null);
    const awarenessReady = ref(false);
    /**
     * Whether the server says this note is written together: its space allows
     * it, or a writing link with live co-editing is open on it. The second
     * reason is not the space's to know, so it comes with the beat.
     */
    const coeditable = ref(false);
    let selfName = null;
    // The address, topic and token a browser needs to say where its cursor
    // is. Null without a hub, and then nothing is published or drawn.
    let awareness = null;
    /** Whoever else reads this channel: the co-editing session subscribes here. */
    const listeners = new Set();
    // When each person's cursor was last heard of.
    const heardAt = new Map();
    let sweeper = null;
    // Which note the running beat belongs to: an answer that arrives after
    // the reader moved on must not fill the room of the note they left.
    let current = null;

    function pathFor(id) {
        return beatPath.replace("__id__", String(id));
    }

    async function beat() {
        const id = noteId.value;
        if (null == id) return;

        // A missing address is a wiring bug - the prop is required, so Vue
        // has already said so loudly. What must not happen on top of that is
        // this taking the page down: nobody asked for the room, and an
        // unhandled rejection from a background beat is invisible until it
        // wipes the screen. Seen before, with `api.preview`.
        if (!beatPath) return;

        const payload = await request(
            pathFor(id),
            { editing: Boolean(editing.value) },
            // `silent`, because nobody asked for this: the page fills the room
            // on its own, and a red toast on every note of the module would be
            // louder than what it reports. `noGuard`, because the page and
            // this legitimately talk to the server at the same time.
            { silent: true, noGuard: true },
        );

        if (!payload || id !== current) return;

        people.value = payload.people ?? [];
        serverVersion.value = payload.version ?? serverVersion.value;
        beatSeconds = payload.beatSeconds ?? beatSeconds;
        selfUserId.value = payload.selfUserId ?? selfUserId.value;
        selfName = payload.selfName ?? selfName;
        // Renewed on every beat, long before the publish token runs out.
        awareness = payload.awareness ?? null;
        awarenessReady.value = null !== awareness;
        coeditable.value = true === payload.coediting;

        connect(payload.streamUrl ?? null);
    }

    function connect(streamUrl) {
        if (!streamUrl || source) {
            // No hub, or already connected: the beat keeps doing the work in
            // the first case, and nothing to redo in the second.
            return;
        }

        // `withCredentials` is what carries the subscription cookie the beat
        // just set. Without it the hub answers 401 and the page would retry
        // for ever against a connection it can never open.
        source = new EventSource(streamUrl, { withCredentials: true });

        source.addEventListener("open", () => {
            live.value = true;
        });

        source.addEventListener("message", (messageEvent) => {
            let message = null;
            try {
                message = JSON.parse(messageEvent.data);
            } catch {
                // A message we cannot read is a message we ignore: the next
                // beat puts the state right either way.
                return;
            }

            if ("presence" === message.kind) {
                // The push carries everybody, this reader included, so they
                // are dropped here - a page showing itself in its own room is
                // the kind of thing nobody reports and everybody notices.
                people.value = (message.people ?? []).filter(
                    (person) => !isSelf(person),
                );

                return;
            }

            if ("changed" === message.kind) {
                changedBy.value = message.by ?? null;
                serverVersion.value = message.version ?? serverVersion.value;

                return;
            }

            if ("cursor" === message.kind) {
                rememberCursor(message);

                return;
            }

            // Everything else on this channel belongs to whoever subscribed
            // to it - the co-editing session, today.
            for (const listener of listeners) listener(message);
        });

        source.addEventListener("error", () => {
            // EventSource retries on its own. What this does is tell the page
            // it is no longer live, so the beat is what notices again.
            live.value = false;
        });
    }

    /**
     * Whether a person in a pushed room is this reader.
     *
     * A page showing itself in its own room is the kind of thing nobody
     * reports and everybody notices. The id comes from the beat, so before
     * the first one has answered the filter lets everybody through - and the
     * first push cannot arrive before the beat that set the cookie.
     */
    function isSelf(person) {
        return (
            null !== selfUserId.value &&
            Number(person.userId) === Number(selfUserId.value)
        );
    }

    /**
     * A cursor somebody else just reported.
     *
     * Keyed by account, so the last position wins and a person who moves does
     * not leave a trail. Dropped when it is this reader's own: a page drawing
     * its own cursor twice is the kind of thing nobody reports and everybody
     * notices.
     */
    function rememberCursor(message) {
        const userId = Number(message.userId);
        if (!Number.isFinite(userId) || userId === Number(selfUserId.value))
            return;

        heardAt.set(userId, Date.now());
        const others = cursors.value.filter((one) => one.userId !== userId);

        if (null == message.index) {
            // Said explicitly: the person left the field, so their cursor
            // stops being drawn without waiting for it to go stale.
            cursors.value = others;

            return;
        }

        cursors.value = [
            ...others,
            {
                userId,
                name: message.name ?? null,
                index: Number(message.index),
            },
        ];
    }

    /** Forgets the cursors nobody has refreshed. */
    function sweepCursors() {
        const cutoff = Date.now() - CURSOR_STALE_MS;

        cursors.value = cursors.value.filter((one) => {
            const at = heardAt.get(one.userId) ?? 0;
            if (at >= cutoff) return true;

            heardAt.delete(one.userId);

            return false;
        });
    }

    /**
     * Says where this reader's cursor is.
     *
     * **Throttled, and silent about its failures.** A caret moves on every
     * keystroke and this is a network call; and nobody asked for it, so a hub
     * that refuses it must not raise anything on screen. `keepalive` so a
     * position published as the tab closes still goes out - which is what
     * makes a cursor disappear when somebody leaves rather than linger.
     */
    async function publish(message) {
        if (!awareness || null == noteId.value) return;

        const body = new URLSearchParams();
        body.append("topic", awareness.topic);
        body.append(
            "data",
            JSON.stringify({ ...message, from: selfUserId.value }),
        );
        // Private, so the hub checks every subscriber's token against the
        // topic instead of handing one note's channel to whoever guesses it.
        body.append("private", "on");

        try {
            await fetch(awareness.publishUrl, {
                method: "POST",
                headers: {
                    Authorization: `Bearer ${awareness.token}`,
                    "Content-Type": "application/x-www-form-urlencoded",
                },
                body,
                // So a message published as the tab closes still goes out -
                // which is what makes a cursor disappear when somebody
                // leaves, rather than linger.
                keepalive: true,
            });
        } catch {
            // Nobody asked for this, so nothing on screen blinks because of
            // it. A cursor publishes again on the next keystroke; a document
            // update is carried by the next one, a CRDT being cumulative.
        }
    }

    async function publishCursor(index) {
        await publish({
            kind: "cursor",
            userId: selfUserId.value,
            name: selfName,
            index: null == index ? null : Number(index),
        });
    }

    function disconnect() {
        if (source) source.close();
        source = null;
        live.value = false;
    }

    function stopBeating() {
        if (timer) clearInterval(timer);
        timer = null;
        if (sweeper) clearInterval(sweeper);
        sweeper = null;
    }

    /**
     * Says this page is leaving the note, so the room drops it at once.
     *
     * Without it a closed tab stayed in everybody's room for fifty seconds,
     * and a page that leaves can be the one the room counts on - to answer a
     * newcomer, or to write the text back. Best effort, by design: a laptop
     * whose lid closes says nothing, and the room still forgets it on its own.
     * `keepalive` is what lets the request leave a closing tab, and why this
     * is a bare `fetch` with the headers `useRequest` sends.
     */
    function leave(id) {
        if (null == id || !beatPath) return;

        try {
            void fetch(pathFor(id), {
                method: "POST",
                keepalive: true,
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ leaving: true }),
            }).catch(() => {});
        } catch {
            // Nobody asked for this; the room forgets the page on its own.
        }
    }

    const onPageHide = () => leave(current);
    window.addEventListener("pagehide", onPageHide);
    onBeforeUnmount(() => {
        window.removeEventListener("pagehide", onPageHide);
        leave(current);
    });

    function start(id) {
        // Moving to another note leaves the one before, without waiting for
        // the room to notice.
        if (null != current && current !== id) leave(current);
        current = id;
        people.value = [];
        cursors.value = [];
        heardAt.clear();
        awareness = null;
        awarenessReady.value = false;
        coeditable.value = false;
        serverVersion.value = null;
        changedBy.value = null;
        disconnect();
        stopBeating();

        if (null == id) return;

        void beat();
        // Kept running even with a hub: it is the heartbeat, and a page that
        // stops beating is a page that drops out of everybody else's room.
        timer = setInterval(() => void beat(), beatSeconds * 1000);
        sweeper = setInterval(sweepCursors, CURSOR_STALE_MS / 2);
    }

    // The open note changes without the page reloading: the room follows.
    watch(noteId, (id) => start(id), { immediate: true });

    // Switching between the editor and the reader changes what the others are
    // told about this reader, so it is worth a beat of its own rather than
    // waiting twenty seconds.
    watch(editing, () => void beat());

    onBeforeUnmount(() => {
        stopBeating();
        disconnect();
    });

    onBeforeUnmount(() => {
        // Said on the way out, so the others stop drawing a cursor that is no
        // longer anywhere. `keepalive` is what lets it leave a closing tab.
        void publishCursor(null);
    });

    /**
     * The channel, as something else can use it.
     *
     * Handed out as one object rather than four refs: a caller that needs to
     * publish also needs to listen, to know who it is, and to know whether
     * there is a hub at all - and passing those separately is how three of
     * them end up wired and the fourth forgotten.
     */
    const channel = {
        publish,
        onMessage: (listener) => {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        selfUserId: () => selfUserId.value,
        // A ref, so a `computed` that depends on it is told when it changes.
        ready: awarenessReady,
        // A ref too, for the same reason: it arrives with the first beat.
        coeditable,
    };

    return {
        people,
        cursors,
        serverVersion,
        changedBy,
        live,
        publishCursor,
        channel,
    };
}
