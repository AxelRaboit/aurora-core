import { computed, onBeforeUnmount, ref, watch } from "vue";

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
export const TABLE_MIN_WIDTH = 768;

/**
 * Whether the element holding a list is too narrow for a table.
 *
 * **The container, never the window.** This is the whole point, and it is not
 * a detail: the back-office sidemenu is 480px fixed from 1024 up and hidden
 * below, so a 768px window leaves a list 718px and a 1024px window leaves it
 * 478px. Widening the screen by 256px costs the list 240px. Every `md:` and
 * `lg:` in a list template is therefore asking the window a question only the
 * panel beside it can answer, and the same list comes out roomier on a tablet
 * than on a small laptop.
 *
 * Bind {@link container} to the element that actually holds the list. A
 * sidemenu that changes width later cannot then silently break ten pages.
 *
 * Lists that also offer a table/cards toggle use {@see useListViewMode}, which
 * is this plus the stored choice. Lists with no toggle - most of them - want
 * only this.
 *
 * @returns {{container: import('vue').Ref, isNarrow: import('vue').ComputedRef<boolean>, width: import('vue').Ref<number>}}
 */
export function useNarrowContainer(minWidth = TABLE_MIN_WIDTH) {
    /** Bind with `ref="container"` on the element that holds the list. */
    const container = ref(null);

    /**
     * Unknown until measured, and unknown means roomy.
     *
     * A server render, a test without ResizeObserver, or the tick before the
     * first observation all land here. Assuming narrow would flash cards on a
     * desktop every time the page loads; assuming roomy shows the shape the
     * reader expects and corrects it within a frame.
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

    return {
        container,
        isNarrow: computed(() => width.value < minWidth),
        width,
    };
}
