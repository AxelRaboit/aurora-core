import { ref } from "vue";
import { COLUMNS } from "./usePostGrid.js";

/**
 * The two handles on a zone's edges, answering to the pointer and the keyboard.
 *
 * Both edges resize. The right one moves the width; the left one moves where the
 * zone starts and leaves the right edge where it is. Moving a zone is a
 * different gesture entirely - take hold of it in the middle - and lives in
 * `usePostGridDrop`.
 *
 * **Nothing is clamped here.** The canvas emits the column the pointer is over
 * and `usePostGrid` decides what that may become: the snap, the floor a left
 * edge cannot cross, the minimum width. Two clamps would be two rules to keep in
 * agreement.
 *
 * The keyboard path is not a nicety. This whole rework began with someone
 * saying that aiming a slider is hard; a canvas that only took a drag would have
 * restated that problem in a worse form. Arrows move by the snap, Home and End
 * ask for the extremes and let the clamp downstream answer.
 *
 * @param {object} deps
 * @param {import("vue").Ref<HTMLElement|null>} deps.gridEl the measured grid
 * @param {import("vue").Ref<number>} deps.snap how far an arrow key moves
 * @param {(index: number) => number} deps.widthOf a zone's current width
 * @param {(index: number) => number} deps.startOf its current first column
 * @param {(event: string, ...args: unknown[]) => void} deps.emit the SFC's emit
 */
export function usePostGridResize({ gridEl, snap, widthOf, startOf, emit }) {
    /** The zone being dragged, so a stray pointermove on another handle is ignored. */
    const dragging = ref(null);

    /** Which handle that gesture is holding - `null`, or `resize` / `start`. */
    const draggingKind = ref(null);

    /** The column under the pointer, as a fraction of the grid's own width. */
    function columnAt(clientX) {
        const rect = gridEl.value?.getBoundingClientRect();

        if (!rect?.width) {
            return null;
        }

        return ((clientX - rect.left) / rect.width) * COLUMNS;
    }

    /**
     * The width the pointer is asking for: the column it sits over, less the
     * column the zone starts on.
     *
     * `startOf` is read fresh on every move rather than frozen at pointerdown. A
     * zone dragged wider than the room left on its row wraps to a row of its own
     * and its start column becomes zero - recomputing keeps the handle under the
     * pointer through that jump. It cannot oscillate: once wrapped, the zone only
     * comes back when it fits again, and a width that fits cannot re-wrap.
     */
    function resizeFromPointer(index, clientX) {
        const column = columnAt(clientX);

        if (null === column) {
            return;
        }

        emit("resize", index, column - startOf(index));
    }

    /**
     * The column the pointer is over, taken as where the zone's left edge goes.
     *
     * Measured from the grid's left edge rather than from the zone, because that
     * is where a row begins and the column is what the answer is expressed in.
     */
    function startFromPointer(index, clientX) {
        const column = columnAt(clientX);

        if (null === column) {
            return;
        }

        emit("resizeStart", index, column);
    }

    function onPointerDown(index, event, kind = "resize") {
        // Stops the drag from selecting the labels it passes over.
        event.preventDefault();
        dragging.value = index;
        draggingKind.value = kind;
        // Optional because jsdom has no pointer capture, and a canvas that
        // throws in its own test is a canvas nobody tests.
        event.currentTarget.setPointerCapture?.(event.pointerId);
        emit("update:selectedIndex", index);
    }

    function onPointerMove(index, event) {
        if (dragging.value !== index) {
            return;
        }

        if ("start" === draggingKind.value) {
            startFromPointer(index, event.clientX);

            return;
        }

        resizeFromPointer(index, event.clientX);
    }

    function onPointerUp(event) {
        dragging.value = null;
        draggingKind.value = null;

        if (event.currentTarget.hasPointerCapture?.(event.pointerId)) {
            event.currentTarget.releasePointerCapture(event.pointerId);
        }
    }

    /** What an arrow, Home or End asks for, from wherever the edge is now. */
    function asked(event, from, home, end) {
        return {
            ArrowLeft: from - snap.value,
            ArrowRight: from + snap.value,
            ArrowDown: from - snap.value,
            ArrowUp: from + snap.value,
            Home: home,
            End: end,
        }[event.key];
    }

    function onKeydown(index, event) {
        const next = asked(event, widthOf(index), snap.value, COLUMNS);

        if (undefined === next) {
            return;
        }

        event.preventDefault();
        emit("resize", index, next);
    }

    /**
     * Home takes the left edge as far left as the order allows, End as far right
     * as the zone's own minimum width leaves - both worked out downstream, so the
     * extremes here are simply out of range and get clamped.
     */
    function onStartKeydown(index, event) {
        const next = asked(event, startOf(index), 0, COLUMNS);

        if (undefined === next) {
            return;
        }

        event.preventDefault();
        emit("resizeStart", index, next);
    }

    return {
        dragging,
        draggingKind,
        onPointerDown,
        onPointerMove,
        onPointerUp,
        onKeydown,
        onStartKeydown,
    };
}
