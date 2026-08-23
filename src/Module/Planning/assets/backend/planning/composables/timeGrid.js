/**
 * The arithmetic behind the day and week views, with no Vue in it.
 *
 * Same reasoning as `monthGrid.js`: everything hard about an hourly view is
 * here - which days it shows, where an event sits in a column, and how two
 * overlapping events share the width - and none of it needs a component mounted
 * to be wrong.
 *
 * Positions come out as fractions of the day, not pixels. The component owns its
 * own row height, and a composable that returned pixels would have to be told it.
 */
import { sameDay, spansDays } from "./monthGrid.js";

/** Monday, like the month grid. Two views disagreeing on that would be a bug. */
const WEEK_STARTS_ON = 1;

const DAY_MS = 86400000;

/** Hour labels down the gutter. */
export const HOURS = Array.from({ length: 24 }, (_, hour) => hour);

/**
 * The shortest block that still reads as a block.
 *
 * A ten-minute event is 0.7% of a column: drawn to scale it is a hairline with
 * no room for its own title. Fifteen minutes of height is the floor, which makes
 * short events slightly too tall rather than invisible - and being able to click
 * the thing is worth more than the height being exact.
 */
const MIN_HEIGHT = 15 / (24 * 60);

function startOfDay(date) {
    const copy = new Date(date);
    copy.setHours(0, 0, 0, 0);

    return copy;
}

function addDays(date, days) {
    const copy = new Date(date);
    copy.setDate(copy.getDate() + days);

    return copy;
}

/** The Monday on or before a date. */
export function weekStart(date) {
    const day = startOfDay(date);

    return addDays(day, -((day.getDay() - WEEK_STARTS_ON + 7) % 7));
}

/**
 * The days a view shows, in order.
 *
 * One for a day view, seven from the Monday for a week view. The anchor is any
 * date inside the range, so paging is "add or subtract the range" and the caller
 * never has to know which Monday it landed on.
 */
export function visibleDays(anchor, view) {
    if ("week" === view) {
        const start = weekStart(anchor);

        return Array.from({ length: 7 }, (_, index) => addDays(start, index));
    }

    return [startOfDay(anchor)];
}

/**
 * The window to ask the server for: the visible days, plus nothing.
 *
 * Unlike the month view, there are no trailing cells from a neighbouring range,
 * so the window is exactly what is drawn.
 */
export function timeGridWindow(anchor, view) {
    const days = visibleDays(anchor, view);

    return { from: days[0], to: addDays(days[days.length - 1], 1) };
}

/**
 * Lay one day's timed events out as positioned blocks.
 *
 * Two passes, and they answer different questions. Clustering asks "which events
 * have to share this column's width", by walking the events in order and closing
 * a cluster whenever one starts after everything before it has ended. Only then
 * does each cluster get its own greedy column allocation - which is what keeps a
 * pair of overlapping events at midday from making a half-width block out of a
 * lone meeting at 17:00.
 *
 * Anything spanning days is excluded: it belongs in the all-day band, the same
 * decision the month view makes with the same function, so an event never
 * appears twice in one screen.
 */
export function layOutDay(day, events) {
    const dayStart = startOfDay(day);
    const dayEnd = addDays(dayStart, 1);

    const blocks = events
        .filter(
            (event) =>
                !spansDays(event) && sameDay(new Date(event.startAt), dayStart),
        )
        .map((event) => ({
            event,
            start: new Date(event.startAt),
            end: new Date(event.endAt),
        }))
        .sort((a, b) => a.start - b.start || b.end - a.end);

    const clusters = [];
    let current = [];
    let clusterEnd = null;

    for (const block of blocks) {
        if (null !== clusterEnd && block.start >= clusterEnd) {
            clusters.push(current);
            current = [];
            clusterEnd = null;
        }

        current.push(block);
        clusterEnd =
            null === clusterEnd
                ? block.end
                : new Date(Math.max(clusterEnd, block.end));
    }

    if (current.length) {
        clusters.push(current);
    }

    const placed = [];

    for (const cluster of clusters) {
        const laneEnds = [];

        for (const block of cluster) {
            let column = laneEnds.findIndex((end) => end <= block.start);
            if (-1 === column) {
                column = laneEnds.length;
            }

            laneEnds[column] = block.end;
            placed.push({ ...block, column });
        }

        // Assigned after the cluster is packed, because a block cannot know how
        // wide it is until its last neighbour has taken a column.
        for (const block of placed.slice(placed.length - cluster.length)) {
            block.columns = laneEnds.length;
        }
    }

    return placed.map((block) => {
        // Clamped to the day so an event running to 00:00 the next morning ends
        // at the bottom of this column rather than one row past it.
        const top = Math.max(0, (block.start - dayStart) / DAY_MS);
        const bottom = Math.min(1, (block.end - dayStart) / DAY_MS);

        return {
            event: block.event,
            top,
            height: Math.max(MIN_HEIGHT, bottom - top),
            column: block.column,
            columns: block.columns,
            continuesAfter: block.end > dayEnd,
        };
    });
}

/**
 * The all-day and multi-day events touching a range, as day spans.
 *
 * Indexed against the visible days rather than against a week, so the same
 * function serves the day view - where the answer is a single cell wide.
 */
export function allDayBand(days, events) {
    if (!days.length) {
        return [];
    }

    const from = days[0];
    const to = addDays(days[days.length - 1], 1);

    return events
        .filter(spansDays)
        .map((event) => ({
            event,
            start: new Date(event.startAt),
            end: new Date(event.endAt),
        }))
        .filter(({ start, end }) => start < to && end > from)
        .sort((a, b) => a.start - b.start || b.end - a.end)
        .map(({ event, start, end }) => {
            const first = Math.max(
                0,
                Math.floor((startOfDay(start) - from) / DAY_MS),
            );
            // The end is inclusive of the day it falls on: an event ending at
            // 23:59 on Wednesday covers Wednesday, one ending at 00:00 does not.
            const lastDay = startOfDay(new Date(end.getTime() - 1));
            const last = Math.min(
                days.length - 1,
                Math.floor((lastDay - from) / DAY_MS),
            );

            return {
                event,
                from: first,
                span: Math.max(1, last - first + 1),
                continuesBefore: start < from,
                continuesAfter: end > to,
            };
        });
}

/**
 * Where "now" sits in the day, as a fraction, or null on a day that is not today.
 *
 * Null rather than a number the caller has to test, so the line either has a
 * position or does not exist - drawing it at 0 on every other day would put a
 * red rule across the top of next Tuesday.
 */
export function nowOffset(day, now = new Date()) {
    if (!sameDay(day, now)) {
        return null;
    }

    return (now - startOfDay(now)) / DAY_MS;
}
