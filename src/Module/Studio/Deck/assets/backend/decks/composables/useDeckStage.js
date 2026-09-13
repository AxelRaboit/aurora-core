import { onBeforeUnmount, ref } from "vue";

/**
 * Two windows showing the same deck, agreeing on which slide is up.
 *
 * **`BroadcastChannel` rather than a handle on the opened window.** A presenter
 * view is opened from the player, so the player could hold its reference and
 * post to it directly; but the two windows are then unequal, and closing the
 * one that happens to be the parent leaves the other deaf. A channel is a room
 * both windows walk into, so either can be closed and reopened mid-talk.
 *
 * The message is the index and nothing else. Sending the slide would mean two
 * copies of the deck that can disagree, and the second window already fetched
 * the deck from the server when it opened.
 *
 * Nothing crosses a browser here: `BroadcastChannel` is same-origin and
 * in-process, so the notes never leave the machine they are read on.
 */
export function useDeckStage(name) {
    const channel = ref(null);
    const at = ref(0);

    /** Set by whichever window last heard from the other. */
    const linked = ref(false);

    const handlers = { move: null };

    if (typeof BroadcastChannel !== "undefined" && name) {
        channel.value = new BroadcastChannel(`aurora-deck-${name}`);

        channel.value.onmessage = (event) => {
            const message = event.data;

            if (!message || typeof message.at !== "number") return;

            linked.value = true;
            at.value = message.at;
            handlers.move?.(message.at);
        };
    }

    /** Say where we are. Called on every step, and once on arrival. */
    function announce(index) {
        at.value = index;
        channel.value?.postMessage({ at: index });
    }

    function onMove(handler) {
        handlers.move = handler;
    }

    onBeforeUnmount(() => {
        channel.value?.close();
        channel.value = null;
    });

    return { at, linked, announce, onMove };
}
