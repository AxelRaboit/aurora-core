import { ref } from "vue";

/**
 * Which zone the panel is showing, kept pointing at the same zone as it moves.
 *
 * Only one zone's fields are on screen at a time, so the selection is not a
 * nicety - it is what the panel *is*. Every operation that reorders, removes or
 * relocates a zone has to say what becomes of it, and each answer is arithmetic
 * on indices rather than a search: two stacks would make a search pick the wrong
 * one.
 *
 * Pulled out of `PostGridPanel.vue` because six functions modelling one thing is
 * exactly what the SFC convention calls a state machine, and because getting any
 * of them wrong leaves the highlight on whichever zone slid into the slot - a
 * defect that is invisible until an author edits the wrong box.
 *
 * UI state, so it lives here rather than in `usePostGrid`: nothing about it is
 * saved, and a composable that owns the document should not grow a field the
 * document does not have.
 *
 * @param {object} grid the operations from `usePostGrid`, which do the writing
 */
export function useGridSelection(grid) {
    const selectedIndex = ref(null);

    /** Follows whatever an operation reports it created, or leaves it alone. */
    function select(at) {
        if (null !== at && undefined !== at) {
            selectedIndex.value = at;
        }
    }

    /**
     * A zone that goes takes the selection with it; a zone above one that goes
     * keeps it. Leaving the index behind would point the highlight at whichever
     * zone slid into the slot.
     */
    function removeZone(index) {
        grid.removeZone(index);

        if (selectedIndex.value === index) {
            selectedIndex.value = null;
        } else if (
            null !== selectedIndex.value &&
            selectedIndex.value > index
        ) {
            selectedIndex.value -= 1;
        }
    }

    /** Reordering by one step - the path that works without a pointer. */
    function moveZone(index, offset) {
        const target = index + offset;

        if (target < 0 || target >= grid.zones.value.length) {
            return;
        }

        grid.moveZone(index, offset);

        if (selectedIndex.value === index) {
            selectedIndex.value = target;
        } else if (selectedIndex.value === target) {
            selectedIndex.value = index;
        }
    }

    /**
     * Only this language's entry is created, and only if the cap allowed it -
     * hence the length check rather than assuming the add worked. A zone added
     * without being selected would land on the canvas and open nothing, which
     * reads as the button having failed.
     */
    function addZone(type) {
        const before = grid.zones.value.length;

        grid.addZone(type);

        if (grid.zones.value.length > before) {
            selectedIndex.value = grid.zones.value.length - 1;
        }
    }

    /** A strip between two rows: a zone of the chosen type, on a row of its own. */
    function addZoneOnNewRow(type, target, newRow) {
        select(grid.addZoneAt(type, target, { newRow }));
    }

    /** A hole in a row: the zone takes its place and its width. */
    function fillGap(type, target, column, width) {
        select(grid.addZoneAt(type, target, { column, width }));
    }

    /**
     * The moved zone leaves the row and lands inside the stack, so the selection
     * follows it there - the stack is what holds it now, and its card is where
     * the fields are. Taking a zone out from *before* the stack shifts the stack
     * down one.
     */
    function moveIntoStack(fromIndex, stackIndex, atIndex) {
        if (!grid.moveZoneIntoStack(fromIndex, stackIndex, atIndex)) {
            return;
        }

        selectedIndex.value =
            fromIndex < stackIndex ? stackIndex - 1 : stackIndex;
    }

    /** Back on the row, at the place it was dropped, which is where the author is looking. */
    function moveOutOfStack(stackIndex, childIndex, atIndex, column, newRow) {
        if (
            grid.moveZoneOutOfStack(
                stackIndex,
                childIndex,
                atIndex,
                column,
                newRow,
            )
        ) {
            selectedIndex.value = atIndex;
        }
    }

    /** Dropped somewhere on the canvas: it is now at the place it was put. */
    function moveZoneTo(index, target, column, newRow) {
        if (grid.moveZoneTo(index, target, column, newRow)) {
            selectedIndex.value = target;
        }
    }

    return {
        selectedIndex,
        addZone,
        addZoneOnNewRow,
        fillGap,
        removeZone,
        moveZone,
        moveZoneTo,
        moveIntoStack,
        moveOutOfStack,
    };
}
