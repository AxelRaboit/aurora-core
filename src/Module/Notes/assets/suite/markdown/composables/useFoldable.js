import { nextTick, ref } from "vue";

/**
 * A control that lives folded into an icon and unfolds on click.
 *
 * The library's bar carries six things; those used in fits and starts -
 * searching, changing the sort - do not have to take up their width all the
 * time. It is Craft's gesture, and it applies twice here, so it lives in a
 * single place.
 *
 * Folding is left to the caller: a search does not close as long as
 * something has been typed in it, a sort closes as soon as a choice is made.
 * What is shared is the opening and handing the keyboard to what appears.
 */
export function useFoldable() {
    const open = ref(false);
    const box = ref(null);

    /**
     * Unfolds, then gives the cursor to the field that has just appeared.
     *
     * The `nextTick` is not decorative: the field does not exist yet at the
     * time of the click, and `focus()` on a missing element does nothing.
     *
     * **The argument can be an event, and that is expected.** Wired as is
     * on a `@click`, this function receives the `PointerEvent` instead of
     * the selector; `querySelector` refuses it by throwing, the promise
     * rejects, and Vue bubbles that rejection up to the page's
     * `errorCaptured`, which then replaces the whole library with its error
     * screen. A click on the magnifier emptied the screen for that reason
     * alone. So a selector that is not a string falls back to the default.
     */
    async function reveal(selector = "input") {
        const css = "string" === typeof selector ? selector : "input";

        open.value = true;

        await nextTick();

        box.value?.querySelector(css)?.focus();
    }

    function fold() {
        open.value = false;
    }

    return { open, box, reveal, fold };
}
