import {
    daysFromPixels,
    minutesFromPixels,
    resizedSpan,
    shiftedSpan,
} from "./timeGrid.js";
import { usePointerDrag } from "./usePointerDrag.js";

/**
 * Dragging and resizing in the hour grid.
 *
 * Two gestures on one machine, told apart by `mode`: `move` shifts the whole
 * block, `resize` moves its end. They share the pointer arithmetic, and the grid
 * cannot be in both at once.
 *
 * @param {object} deps
 * @param {import("vue").Ref<HTMLElement|null>} deps.columnsBox  the box pixels are read against
 * @param {import("vue").Ref<Array>|{value: Array}} deps.days    the columns on screen
 * @param {(payload: object) => void} deps.onMove                a span the reader committed to
 * @param {(event: object) => void} deps.onOpen                  a click that was not a drag
 */
export function useTimeGridDrag({ columnsBox, days, onMove, onOpen }) {
    const { drag, begin, wasDragged } = usePointerDrag({
        onMove(current, pointerEvent) {
            if (null === columnsBox.value) {
                return;
            }

            const box = columnsBox.value.getBoundingClientRect();

            current.minutes = minutesFromPixels(
                pointerEvent.clientY - current.originY,
                box.height,
            );
            // Sideways only when moving. Resizing an event into another day is not
            // a gesture anybody makes on purpose, and a wobbling hand would do it.
            current.days =
                "move" === current.mode
                    ? daysFromPixels(
                          pointerEvent.clientX - current.originX,
                          box.width / days.value.length,
                      )
                    : 0;
        },

        onEnd(current) {
            if (0 === current.minutes && 0 === current.days) {
                // Dragged and put back. Nothing to save, and posting an unchanged
                // span would write an audit line saying somebody moved it.
                return;
            }

            const span =
                "move" === current.mode
                    ? shiftedSpan(
                          current.startAt,
                          current.endAt,
                          current.minutes,
                          current.days,
                      )
                    : resizedSpan(
                          current.startAt,
                          current.endAt,
                          current.minutes,
                      );

            onMove({ id: current.id, event: current.event, ...span });
        },
    });

    function onBlockPointerDown(block, mode, pointerEvent) {
        // Left button only. A right-click is a context menu and a middle-click is
        // a scroll; neither is a request to move a meeting.
        if (0 !== pointerEvent.button || block.event.readOnly) {
            return;
        }

        pointerEvent.stopPropagation();

        begin(pointerEvent, {
            id: block.event.id,
            // Carried so the app can tell a series from a single event without
            // looking it up. Without it a drag on one occurrence moved the whole
            // series, and silently: nothing asked which it meant.
            event: block.event,
            mode,
            startAt: block.event.startAt,
            endAt: block.event.endAt,
            minutes: 0,
            days: 0,
        });
    }

    function onBlockClick(event) {
        if (wasDragged()) {
            return;
        }

        onOpen(event);
    }

    /**
     * How far to draw a block from where its data says it is.
     *
     * The grid shows the drag before the server has agreed to it, because a block
     * that only moves after a round trip feels like it did not take.
     */
    function dragOffset(block) {
        const current = drag.value;
        if (null === current || current.id !== block.event.id) {
            return { top: 0, height: 0, days: 0 };
        }

        const perMinute = 1 / 1440;

        return "move" === current.mode
            ? {
                  top: current.minutes * perMinute,
                  height: 0,
                  days: current.days,
              }
            : { top: 0, height: current.minutes * perMinute, days: 0 };
    }

    return { drag, onBlockPointerDown, onBlockClick, dragOffset };
}
