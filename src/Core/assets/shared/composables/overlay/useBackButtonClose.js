import { onBeforeUnmount, onMounted, ref, unref, watch } from "vue";

/**
 * Wires the browser Back button to close an overlay/modal.
 *
 * On open: pushes a history entry. On Back: popstate fires and we call onClose.
 * Programmatic close (X, Escape, backdrop, parent state change) flips `show`
 * which triggers the watch - the watch cleans up the history entry by calling
 * `history.back()` itself.
 *
 * Stacked overlays work naturally - each instance pushes its own entry and the
 * browser pops them LIFO.
 *
 * Chained close+open (modal A closes while modal B opens in the same tick) is
 * tricky: the `history.back()` from A's close triggers a popstate that *all*
 * registered listeners receive. Without coordination, B's listener would treat
 * it as a user-initiated back and close B too. We track in-flight programmatic
 * `back()`s via a module-scoped counter and tag the popstate event itself so
 * only the first matching listener consumes it.
 *
 * @param {object} options
 * @param {import('vue').Ref<boolean> | (() => boolean)} options.isOpen
 * @param {() => void} options.onClose
 */

let internalBacksPending = 0;

export function useBackButtonClose({ isOpen, onClose }) {
    const pushed = ref(false);
    const read = () =>
        typeof isOpen === "function" ? isOpen() : unref(isOpen);

    function onPopState(event) {
        if (event.__overlayBackHandled) return;
        if (internalBacksPending > 0) {
            internalBacksPending--;
            event.__overlayBackHandled = true;
            return;
        }
        if (!pushed.value) return;
        pushed.value = false;
        event.__overlayBackHandled = true;
        onClose();
    }

    function programmaticBack() {
        internalBacksPending++;
        history.back();
    }

    watch(read, (open) => {
        if (open && !pushed.value) {
            history.pushState({ __overlayBack: true }, "");
            pushed.value = true;
        } else if (!open && pushed.value) {
            pushed.value = false;
            programmaticBack();
        }
    });

    onMounted(() => window.addEventListener("popstate", onPopState));
    onBeforeUnmount(() => {
        window.removeEventListener("popstate", onPopState);
        if (pushed.value) {
            pushed.value = false;
            programmaticBack();
        }
    });

    function requestClose() {
        // Let the parent flip `show` to false; the watch above will handle
        // history cleanup. Going through the watch keeps a single code path
        // for every close trigger (X, Escape, backdrop, parent state change).
        onClose();
    }

    return { requestClose };
}
