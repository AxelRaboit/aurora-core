import { onBeforeUnmount, ref } from "vue";

/**
 * Whether a drag has moved far enough to be a drag.
 *
 * Without a threshold every click is a zero-pixel move, so the grid would post an
 * update on each one - and the click that opens the event would stop working,
 * because the pointer handler would have claimed it.
 */
const THRESHOLD_PX = 4;

/**
 * The pointer-drag lifecycle both grids run: begin, follow, end, and suppress the
 * click that follows.
 *
 * The month grid and the hour grid had a copy each, identical in everything except
 * what a move means - whole days there, minutes and columns here. That difference
 * is the two callbacks; everything around it is this.
 *
 * Listeners go on `window` rather than the block, because a pointer that leaves
 * the element mid-gesture must not end the drag: the reader is dragging to
 * somewhere else on the grid, which is the whole point.
 *
 * @param {object}   handlers
 * @param {(drag: object, pointerEvent: PointerEvent) => void} [handlers.onMove]
 * @param {(drag: object, pointerEvent: PointerEvent) => void} handlers.onEnd
 *        Called only when the gesture passed the threshold.
 */
export function usePointerDrag({ onMove, onEnd }) {
    /**
     * The drag in progress, or null.
     *
     * One object rather than a ref per field, so a half-finished drag cannot
     * exist: either every field is there or the gesture is not happening.
     */
    const drag = ref(null);

    /**
     * Set when a drag ends, so the click that follows it does nothing.
     *
     * A browser fires `click` after `pointerup` on the same element, so without
     * this every drag also opened the event's modal. Cleared when it is read
     * rather than on a timer: if no click follows - the pointer left the block, or
     * the gesture was cancelled - the flag must not swallow the next real click.
     */
    let endedWithMovement = false;

    function begin(pointerEvent, state) {
        endedWithMovement = false;

        drag.value = {
            ...state,
            originX: pointerEvent.clientX,
            originY: pointerEvent.clientY,
            moved: false,
        };

        window.addEventListener("pointermove", handleMove);
        window.addEventListener("pointerup", handleUp);
    }

    function handleMove(pointerEvent) {
        const current = drag.value;
        if (null === current) {
            return;
        }

        if (
            Math.abs(pointerEvent.clientX - current.originX) > THRESHOLD_PX ||
            Math.abs(pointerEvent.clientY - current.originY) > THRESHOLD_PX
        ) {
            current.moved = true;
        }

        onMove?.(current, pointerEvent);
    }

    function handleUp(pointerEvent) {
        const current = drag.value;
        drag.value = null;
        window.removeEventListener("pointermove", handleMove);
        window.removeEventListener("pointerup", handleUp);

        if (null === current || !current.moved) {
            return;
        }

        // Dragged, so whatever the browser sends next is not a click on the
        // event. Set before `onEnd` decides whether there is anything to save: a
        // drag that ended where it started is still not a click.
        endedWithMovement = true;

        onEnd(current, pointerEvent);
    }

    /**
     * Whether the click now arriving is the tail of a drag, clearing the flag as
     * it answers.
     *
     * Guarding the click rather than calling `preventDefault` on `pointerdown`,
     * which would also stop the browser giving the block focus - and then the
     * keyboard could not reach it at all.
     */
    function wasDragged() {
        if (!endedWithMovement) {
            return false;
        }

        endedWithMovement = false;

        return true;
    }

    onBeforeUnmount(() => {
        window.removeEventListener("pointermove", handleMove);
        window.removeEventListener("pointerup", handleUp);
    });

    return { drag, begin, wasDragged };
}
