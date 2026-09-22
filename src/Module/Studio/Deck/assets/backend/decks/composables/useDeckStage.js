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
 * The message is the index and how many of the slide's lines are out, and
 * nothing else. Sending the slide would mean two copies of the deck that can
 * disagree, and the second window already fetched the deck from the server when
 * it opened; sending only the index was worse, because the window that presses
 * the key is not always the window that projects, and the lines then arrived
 * all at once on the wall while the presenter thought they were stepping.
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
            handlers.move?.(
                message.at,
                Number.isInteger(message.shown) ? message.shown : 0,
            );
        };
    }

    /** Say where we are. Called on every step, and once on arrival. */
    function announce(index, shown = 0) {
        at.value = index;
        channel.value?.postMessage({ at: index, shown });
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
