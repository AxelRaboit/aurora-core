import { computed, onBeforeUnmount, ref, watch } from "vue";
import { useQueryState } from "@/shared/composables/useQueryState.js";

/**
 * The width a list needs before a table is the better shape.
 *
 * Measured, not guessed: across the ten back-office lists the tables ask for
 * between 539 and 986 pixels. Below 768 none of them is comfortable, and the
 * column that falls out of the frame is always the last one - Actions, which
 * is the only reason a row is opened.
 *
 * One number for every list rather than one per screen. A threshold tuned per
 * table is a threshold nobody can hold in their head, and the next list added
 * would get its own.
 */
const TABLE_MIN_WIDTH = 768;

/**
 * List view toggle - "grid" ↔ "list", or whatever a caller declares.
 *
 * In the query string rather than localStorage, for the same reason as the
 * sort beside it: it describes the page being looked at, so it belongs to the
 * link. The default is left out of the URL so an untouched list has a clean
 * address.
 *
 * **The shape is decided by the container, never by the window.** This is the
 * whole point, and it is not a detail: the back-office sidemenu is 480px fixed
 * from 1024 up and hidden below, so a 768px window leaves a list 718px and a
 * 1024px window leaves it 478px. Widening the screen by 256px costs the list
 * 240px. Every `md:` and `lg:` in a list template is therefore asking the
 * window a question only the panel beside it can answer, and the same list is
 * roomier on a tablet than on a small laptop.
 *
 * Bind {@link container} to the element that actually holds the list, and the
 * question is asked of the right thing. A sidemenu that changes width later
 * cannot silently break ten pages.
 *
 * **The choice is overruled, never erased.** A narrow container renders cards
 * whatever the URL says; the stored choice stays in `storedViewMode` and comes
 * back the moment there is room for it.
 *
 * @param {string[]} [modes=["grid", "list"]] Allowed values; anything else is discarded.
 * @param {string}   [defaultMode="grid"]
 * @param {string}   [param="view"]           Query parameter name.
 */
export function useListViewMode(
    modes = ["grid", "list"],
    defaultMode = "grid",
    param = "view",
) {
    const { value, set } = useQueryState(param, {
        defaultValue: defaultMode,
        valid: modes,
    });

    /** Bind with `ref="container"` on the element that holds the list. */
    const container = ref(null);

    /**
     * Unknown until measured, and unknown means roomy.
     *
     * A server render, a test without ResizeObserver, or the tick before the
     * first observation all land here. Assuming narrow would flash cards on a
     * desktop every time the page loads; assuming roomy shows the shape the
     * reader asked for and corrects it within a frame.
     */
    const width = ref(Number.POSITIVE_INFINITY);

    const canObserve =
        typeof window !== "undefined" &&
        typeof window.ResizeObserver === "function";

    let observer = null;

    function observe(element) {
        if (null !== observer) {
            observer.disconnect();
            observer = null;
        }

        if (!canObserve || !element) {
            return;
        }

        observer = new window.ResizeObserver((entries) => {
            for (const entry of entries) {
                // `contentRect` rather than `borderBoxSize`: padding is not
                // room the table can use, and the older shape is the one every
                // browser agrees on.
                width.value = entry.contentRect.width;
            }
        });

        observer.observe(element);
    }

    watch(container, observe, { immediate: true });

    onBeforeUnmount(() => {
        if (null !== observer) {
            observer.disconnect();
            observer = null;
        }
    });

    /** Too narrow for a table, whatever the window is doing. */
    const isNarrow = computed(() => width.value < TABLE_MIN_WIDTH);

    const viewMode = computed(() =>
        // Only when there is something to fall back to: a list that declares
        // no card view has nothing better to offer and keeps what it has.
        isNarrow.value && modes.includes("grid") ? "grid" : value.value,
    );

    return {
        viewMode,
        setViewMode: set,
        /** What the reader chose, which a narrow container overrules without erasing. */
        storedViewMode: value,
        container,
        isNarrow,
        TABLE_MIN_WIDTH,
    };
}
