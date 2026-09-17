import { computed, onBeforeUnmount, onMounted, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * A space's conversation, and the three ways it can stay up to date.
 *
 * **The message list is the state, and everything else writes into it.** A
 * write answers with the whole window, the hub pushes one message at a time,
 * and a reconnection asks for the window again. All three go through `merge`,
 * which is keyed by id - so the same message arriving twice, by two different
 * roads, is one row. That is the whole reason the server sends the same shape
 * down both pipes.
 *
 * **Live is an improvement, not a requirement.** With a hub, messages arrive as
 * they are written. Without one - and most installations will not have one -
 * the panel asks the server every `POLL_MS` while it is on screen, which is
 * what a chat was before push existed. The page is told which it is by being
 * handed a stream address or null; it never has to guess.
 *
 * **A reconnection always refetches.** An `EventSource` that drops and comes
 * back has a hole in it, and the messages written during the gap were pushed to
 * nobody. Asking for the window on every open - the first excepted, the page
 * arrived with it - is the difference between a chat that recovers from a lost
 * connection and one that quietly stops being right.
 *
 * @param {Array}  initial  the window the page was rendered with
 * @param {object} paths    postPath, reloadPath, deletePath?, streamUrl?
 */
export function useSpaceChat(initial, paths) {
    const { t } = useI18n();
    const { request } = useRequest();

    const messages = ref(sorted(initial ?? []));
    const loading = ref(false);

    /**
     * Whether a hub is pushing to this page right now.
     *
     * Three states in two booleans, and the panel says all three: connected,
     * polling because there is no hub, and polling because the hub was there
     * and went. The last one is the one worth showing - somebody typing into a
     * chat that has silently stopped being live deserves to know.
     */
    const live = ref(false);
    const expectsLive = computed(() => !!paths.streamUrl);

    /** While the tab is in the background nothing is asked for. */
    const POLL_MS = 20000;

    let source = null;
    let poller = null;
    let connectedOnce = false;

    function sorted(list) {
        return [...list].sort(
            (a, b) =>
                new Date(a.createdAt) - new Date(b.createdAt) || a.id - b.id,
        );
    }

    /**
     * Folds whatever arrived into the list.
     *
     * Accepts one message or many, and a message carrying `deleted` removes the
     * row instead of adding it: the page is maintaining a list, and everything
     * that happens to that list is a message changing.
     */
    function merge(incoming) {
        const list = Array.isArray(incoming) ? incoming : [incoming];
        const byId = new Map(
            messages.value.map((message) => [message.id, message]),
        );

        for (const message of list) {
            if (!message || undefined === message.id) continue;

            if (message.deleted) {
                byId.delete(message.id);
                continue;
            }

            byId.set(message.id, message);
        }

        messages.value = sorted([...byId.values()]);
    }

    async function reload() {
        const data = await request(paths.reloadPath, null, {
            method: HttpMethod.Get,
            // Nobody asked for this one: it is a reconnection or a poll tick,
            // and a red toast for each is louder than the thing it reports.
            silent: true,
            // Without this a tick landing during a send is dropped by the
            // shared loading guard, which is exactly when the list is most
            // likely to be behind.
            noGuard: true,
        });

        if (!data?.success) return;

        // Replaced rather than merged: this is the authoritative window, and a
        // merge would resurrect a message deleted while the page was away.
        messages.value = sorted(data.chatMessages ?? []);
    }

    async function post(body) {
        const text = (body ?? "").trim();
        if ("" === text || loading.value) return false;

        loading.value = true;
        try {
            const data = await request(paths.postPath, { body: text });

            if (!data?.success) return false;

            messages.value = sorted(data.chatMessages ?? []);

            return true;
        } finally {
            loading.value = false;
        }
    }

    async function remove(message) {
        if (!message || !paths.deletePath || loading.value) return;

        loading.value = true;
        try {
            const data = await request(
                paths.deletePath.replace("__id__", message.id),
            );

            if (!data?.success) return;

            messages.value = sorted(data.chatMessages ?? []);
            toast.success(t("shared.space_chat.deleted"));
        } finally {
            loading.value = false;
        }
    }

    function connect() {
        // `withCredentials` is what carries the subscription cookie. Without
        // it the hub sees an anonymous subscriber and refuses a private topic,
        // which looks exactly like a hub that is down.
        source = new EventSource(paths.streamUrl, { withCredentials: true });

        source.onopen = () => {
            live.value = true;

            if (connectedOnce) {
                // See the docblock: a reconnection has a hole in it.
                void reload();
            }

            connectedOnce = true;
        };

        source.onmessage = (event) => {
            try {
                merge(JSON.parse(event.data));
            } catch {
                // A frame we cannot read is not worth breaking the panel for;
                // the next poll or reconnection puts the list right.
            }
        };

        source.onerror = () => {
            // EventSource retries on its own. What this does is tell the page
            // it is no longer live, so the notice appears and the poller takes
            // over until it comes back.
            live.value = false;
        };
    }

    function tick() {
        if ("visible" !== document.visibilityState) return;
        if (live.value) return;

        void reload();
    }

    onMounted(() => {
        if (expectsLive.value) connect();

        // Started in both cases, and idle while the hub is connected: it is
        // the safety net for a dropped connection as much as the mechanism for
        // an installation without a hub.
        poller = setInterval(tick, POLL_MS);
    });

    onBeforeUnmount(() => {
        source?.close();
        source = null;

        if (poller) clearInterval(poller);
        poller = null;
    });

    return {
        messages,
        loading,
        live,
        expectsLive,
        post,
        remove,
        reload,
        merge,
    };
}
