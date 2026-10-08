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

    /** The others on the note; never includes oneself. */
    const people = ref([]);

    /** The version the server last told us about, by either road. */
    const serverVersion = ref(null);

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
    let selfUserId = null;
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
        selfUserId = payload.selfUserId ?? selfUserId;

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
            }
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
            null !== selfUserId && Number(person.userId) === Number(selfUserId)
        );
    }

    function disconnect() {
        if (source) source.close();
        source = null;
        live.value = false;
    }

    function stopBeating() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    function start(id) {
        current = id;
        people.value = [];
        serverVersion.value = null;
        changedBy.value = null;
        disconnect();
        stopBeating();

        if (null == id) return;

        void beat();
        // Kept running even with a hub: it is the heartbeat, and a page that
        // stops beating is a page that drops out of everybody else's room.
        timer = setInterval(() => void beat(), beatSeconds * 1000);
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

    return { people, serverVersion, changedBy, live };
}
