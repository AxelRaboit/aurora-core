import { shiftedSpan } from "./timeGrid.js";
import { usePointerDrag } from "./usePointerDrag.js";

/**
 * Dragging an event from one day to another in the month grid.
 *
 * Whole days only: an event dragged from Tuesday to Friday keeps its time,
 * because the cell it lands in says nothing about hours.
 *
 * @param {object} deps
 * @param {(payload: object) => void} deps.onMove  a span the reader committed to
 * @param {(event: object) => void} deps.onOpen    a click that was not a drag
 */
export function useMonthDrag({ onMove, onOpen }) {
    /**
     * The day a cell stands for, read back off the DOM.
     *
     * Computed from the pointer rather than from arithmetic on a row height,
     * because the rows here are not all the same height - a week carrying two bars
     * reserves two lanes and the one below it none.
     */
    function dayUnder(x, y) {
        const cell = document.elementFromPoint(x, y)?.closest("[data-day]");

        return cell?.dataset.day ?? null;
    }

    /**
     * Where the pointer grabbed, as a date.
     *
     * A chip lives inside its cell, so it can be asked. A bar is positioned over
     * the whole week and has no cell to climb to, so its column comes from the
     * pointer's position across a row that is - horizontally - seven equal parts.
     */
    function grabbedDay(pointerEvent, week) {
        const fromChip =
            pointerEvent.target.closest?.("[data-day]")?.dataset.day;
        if (fromChip) {
            return fromChip;
        }

        const row = pointerEvent.currentTarget.closest("[data-week]");
        if (!row) {
            return null;
        }

        const box = row.getBoundingClientRect();
        const column = Math.min(
            6,
            Math.max(
                0,
                Math.floor((pointerEvent.clientX - box.left) / (box.width / 7)),
            ),
        );

        return week.cells[column]?.key ?? null;
    }

    const { drag, begin, wasDragged } = usePointerDrag({
        onEnd(current, pointerEvent) {
            const to = dayUnder(pointerEvent.clientX, pointerEvent.clientY);
            if (null === to || to === current.from) {
                // Dropped outside the grid, or back where it started. Nothing to
                // save, and posting an unchanged span would log a move.
                return;
            }

            // Whole days, computed from the two dates rather than from pixels: the
            // cells already told us which days these are.
            const days = Math.round(
                (new Date(to) - new Date(current.from)) / 86400000,
            );

            onMove({
                id: current.event.id,
                // Carried for the same reason the hourly grid carries it: the app
                // has to know whether this is one occurrence of a series before it
                // writes.
                event: current.event,
                ...shiftedSpan(
                    current.event.startAt,
                    current.event.endAt,
                    0,
                    days,
                ),
            });
        },
    });

    function onEventPointerDown(event, week, pointerEvent) {
        if (0 !== pointerEvent.button || event.readOnly) {
            return;
        }

        const from = grabbedDay(pointerEvent, week);
        if (null === from) {
            return;
        }

        begin(pointerEvent, { event, from });
    }

    function onEventClick(event) {
        if (wasDragged()) {
            return;
        }

        onOpen(event);
    }

    return { drag, onEventPointerDown, onEventClick };
}
