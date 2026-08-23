import { computed, ref } from "vue";
import { COLUMNS, largeSpan, planMove } from "./usePostGrid.js";

/**
 * Everything a drag can mean on the canvas, and where each one lands.
 *
 * Four gestures share one mechanism, and the whole difficulty is telling them
 * apart by what is under the pointer when it is let go:
 *
 * | Dropped on | Means |
 * |---|---|
 * | another zone's box | exchange the two |
 * | a slice of a stack | put the zone inside it, there |
 * | the empty canvas | move the zone to that column, that row |
 * | the empty canvas, from a stack | take the zone out and put it there |
 *
 * Native drag and drop rather than pointer events with a movement threshold: the
 * browser already knows the difference between a click and a drag, draws the
 * ghost, and leaves the box's own click free to go on selecting.
 *
 * A box **stops** its own dragover rather than letting it reach the canvas
 * behind. Without that, hovering the zone you are holding cancelled the dragover
 * through the grid and then dropped to nothing - a cursor promising a move that
 * never came.
 *
 * No keyboard equivalent, and none is owed: the up/down buttons on the selected
 * zone reorder without a pointer, and that is the path that has to work.
 *
 * @param {object} deps
 * @param {import("vue").Ref<Array<object>>} deps.zones the arrangement, in order
 * @param {import("vue").Ref<HTMLElement|null>} deps.gridEl the measured grid
 * @param {(event: string, ...args: unknown[]) => void} deps.emit the SFC's emit
 */
export function usePostGridDrop({ zones, gridEl, emit }) {
    /** The zone being dragged on the row, by index, or null. */
    const draggingFrom = ref(null);

    /**
     * The slice being dragged out of a stack, as `{stackIndex, childIndex}`.
     *
     * Held apart from `draggingFrom` because the two answer different questions
     * - one names a zone on the row, the other a zone inside a stack - and a
     * single field would have to be read twice to tell which.
     */
    const draggingSlice = ref(null);

    /** The box that would take an exchange, and the slice that would take a zone. */
    const dropTarget = ref(null);
    const dropSlice = ref(null);

    /** Where a drop on the empty canvas would put the zone, computed for the ghost. */
    const dropPlan = ref(null);

    function onDragStart(index, event) {
        draggingFrom.value = index;
        // Firefox starts no drag at all unless something is written here.
        event.dataTransfer?.setData("text/plain", String(index));

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = "move";
        }
    }

    function onDragOver(index, event) {
        // A box covers part of the canvas, and the canvas behind it means "move
        // the zone here". Over a box the gesture means something else, so the
        // event stops here rather than being claimed by both.
        event.stopPropagation();
        dropPlan.value = null;

        // A slice on its way out of a stack lands here too - the box says "on
        // the row, at this place", which is the only thing leaving can mean.
        if (null !== draggingSlice.value) {
            event.preventDefault();
            dropTarget.value = index;

            return;
        }

        if (null === draggingFrom.value || draggingFrom.value === index) {
            return;
        }

        // A dragover that is not cancelled means "no drop here", so this is what
        // makes the box a target at all rather than a nicety.
        event.preventDefault();
        dropTarget.value = index;
    }

    /**
     * Whether a slice may take what is being dragged.
     *
     * One predicate for both handlers. A browser will not fire `drop` on a
     * target whose `dragover` was not cancelled, so asking twice looks
     * redundant - but that is the browser enforcing our rule for us, and a rule
     * enforced somewhere we do not control is a rule that holds until it does
     * not.
     *
     * A stack refuses a stack because depth stops at one: the normaliser would
     * drop it on the way out, which loses a zone silently rather than refusing
     * a move.
     */
    function sliceAccepts(stackIndex) {
        const from = draggingFrom.value;

        return (
            null !== from &&
            from !== stackIndex &&
            "stack" !== zones.value[from]?.type
        );
    }

    function onSliceDragStart(stackIndex, childIndex, event) {
        // Stops the stack's own box from starting a drag of the whole stack.
        event.stopPropagation();
        draggingSlice.value = { stackIndex, childIndex };
        event.dataTransfer?.setData(
            "text/plain",
            `${stackIndex}:${childIndex}`,
        );

        if (event.dataTransfer) {
            event.dataTransfer.effectAllowed = "move";
        }
    }

    function onSliceOver(stackIndex, atIndex, event) {
        if (!sliceAccepts(stackIndex)) {
            return;
        }

        event.preventDefault();
        // Stops the stack's own box from also claiming the drop as an exchange.
        event.stopPropagation();
        dropSlice.value = `${stackIndex}:${atIndex}`;
        dropTarget.value = null;
    }

    function onSliceDrop(stackIndex, atIndex, event) {
        if (!sliceAccepts(stackIndex)) {
            // Left to bubble on purpose: the box behind takes it as the exchange
            // every drop means by default, so the gesture does something rather
            // than nothing.
            return;
        }

        event.stopPropagation();
        emit("moveInto", draggingFrom.value, stackIndex, atIndex);
        onDragEnd();
    }

    function onDrop(index, event) {
        // Stops the grid behind from also taking it as a move to empty space. A
        // drop on a box means exchange, and only that.
        event?.stopPropagation();

        if (null !== draggingSlice.value) {
            const { stackIndex, childIndex } = draggingSlice.value;
            emit("moveOut", stackIndex, childIndex, index);
            onDragEnd();

            return;
        }

        if (null !== draggingFrom.value && draggingFrom.value !== index) {
            emit("swap", draggingFrom.value, index);
            // The zone the author was holding is now here, and it is the one
            // they were working on.
            emit("update:selectedIndex", index);
        }

        onDragEnd();
    }

    function onDragEnd() {
        draggingFrom.value = null;
        draggingSlice.value = null;
        dropTarget.value = null;
        dropSlice.value = null;
        dropPlan.value = null;
    }

    /**
     * The column under the pointer, and where the zone would sit in the order.
     *
     * Read off the pointer rather than off a list of slots. A slot would have to
     * be drawn, and the ones worth drawing move while they are being aimed at:
     * the rows re-flow as widths shift. The pointer is over one column of 48 and
     * either inside a row or between two, and those two facts are the whole of
     * the answer.
     *
     * A zone counts as "before the drop" when it is on an earlier row, or on the
     * same one and more than half passed - the midpoint rather than the edge, so
     * the answer changes where the pointer visibly crosses a box rather than at
     * the moment it leaves one.
     *
     * `newRow` is true when the pointer is in none of the rows: the gap between
     * two, or the space below the last. That is the only place a break can be
     * asked for with a drop, and it reads as one - you are putting the zone
     * between things.
     *
     * @param {number} ignoreIndex the box not to count, because it is about to
     *   leave the list. A slice coming out of a stack takes nothing off the row,
     *   so it passes `-1`: the stack is still there and still ahead of a drop
     *   past it.
     */
    function dropAt(event, ignoreIndex = draggingFrom.value) {
        const rect = gridEl.value?.getBoundingClientRect();

        if (!rect?.width) {
            return null;
        }

        const column = Math.max(
            0,
            Math.min(
                COLUMNS - 1,
                Math.floor(
                    ((event.clientX - rect.left) / rect.width) * COLUMNS,
                ),
            ),
        );

        let target = 0;
        let newRow = true;

        // Only the zone boxes: the grid also holds the between-row strips, the
        // targets over the holes and the drop ghost, and counting any of them as
        // a zone would put the drop somewhere other than where it was aimed.
        gridEl.value
            .querySelectorAll(":scope > [data-zone]")
            .forEach((child) => {
                const index = Number(child.dataset.zone);

                if (index === ignoreIndex) {
                    return;
                }

                const box = child.getBoundingClientRect();

                if (box.bottom < event.clientY) {
                    target += 1;

                    return;
                }

                if (box.top > event.clientY) {
                    return;
                }

                // Inside this row, so the drop joins it rather than opening one.
                newRow = false;

                if (box.left + box.width / 2 < event.clientX) {
                    target += 1;
                }
            });

        return { target, column, newRow };
    }

    function onGridOver(event) {
        if (null === draggingFrom.value) {
            // A slice on its way out of a stack: the canvas takes it as readily
            // as a box does, and says so by cancelling. It gets no ghost - where
            // it lands depends on a width it does not have yet, since a share of
            // a height is not a width and it is given a fresh one on the way out.
            if (null !== draggingSlice.value) {
                event.preventDefault();
            }

            return;
        }

        const at = dropAt(event);

        if (null === at) {
            return;
        }

        event.preventDefault();
        dropPlan.value = {
            ...at,
            plan: planMove(
                zones.value,
                draggingFrom.value,
                at.target,
                at.column,
                at.newRow,
            ),
        };
    }

    function onGridDrop(event) {
        // Leaving a stack for the empty canvas, rather than for another zone's
        // box. Without this a slice could only come out onto a box, which made
        // the only way out of a stack an exchange with something already there.
        if (null !== draggingSlice.value) {
            const { stackIndex, childIndex } = draggingSlice.value;
            const at = dropAt(event, -1);

            if (null !== at) {
                emit(
                    "moveOut",
                    stackIndex,
                    childIndex,
                    at.target,
                    at.column,
                    at.newRow,
                );
            }

            onDragEnd();

            return;
        }

        const at = dropAt(event);

        if (null !== at && null !== draggingFrom.value) {
            emit("move", draggingFrom.value, at.target, at.column, at.newRow);
            // The zone the author was holding has moved, and it is the one they
            // were working on - its new index is where it was put.
            emit("update:selectedIndex", at.target);
        }

        onDragEnd();
    }

    /**
     * Where the dragged zone would land, in the properties every box uses.
     *
     * Drawn from `planMove`, which is also what applies the move - so what is
     * promised under the pointer is what letting go produces, rather than a
     * second guess at it.
     */
    const ghostStyle = computed(() => {
        const plan = dropPlan.value?.plan;

        if (!plan || null === draggingFrom.value) {
            return null;
        }

        return {
            "--span-base": largeSpan(zones.value[draggingFrom.value]),
            "--row-base": plan.place.row * 2,
            "--start-base": plan.place.column,
        };
    });

    return {
        draggingFrom,
        draggingSlice,
        dropTarget,
        dropSlice,
        ghostStyle,
        onDragStart,
        onDragOver,
        onDrop,
        onDragEnd,
        onSliceDragStart,
        onSliceOver,
        onSliceDrop,
        onGridOver,
        onGridDrop,
    };
}
