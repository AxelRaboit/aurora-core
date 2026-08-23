/**
 * The date arithmetic behind a month view, with no Vue in it.
 *
 * Kept as plain functions because this is the part that is worth testing on its
 * own: everything hard about a month grid is here - which days it shows, which
 * events touch which week, and how two events that overlap avoid each other -
 * and none of it needs a component mounted to be wrong.
 *
 * Dates are handled in the viewer's own zone. A calendar carries a timezone and
 * this does not read it yet: doing that properly means every comparison happens
 * in that zone, not just the display, and it is a change to make deliberately
 * rather than half.
 */

/** Monday. A grid that started on Sunday would be a different product here. */
const WEEK_STARTS_ON = 1;

/** Rows drawn, always. See `monthGrid`. */
export const WEEKS_SHOWN = 6;

/** Bars drawn in a week before the rest become "+n". */
export const MAX_LANES = 3;

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

export function sameDay(a, b) {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

/**
 * The Monday on or before the first of the month.
 *
 * `getDay()` is 0 for Sunday, so the offset is taken modulo 7 rather than
 * subtracted directly: on a Sunday the naive arithmetic jumps forward six days
 * and the grid starts after the month it is drawing.
 */
export function gridStart(year, month) {
    const first = startOfDay(new Date(year, month, 1));
    const offset = (first.getDay() - WEEK_STARTS_ON + 7) % 7;

    return addDays(first, -offset);
}

/**
 * The window to ask the server for: the whole grid, not the whole month.
 *
 * A month view shows days from the months either side, and an event on one of
 * those is an event the reader can see. Asking for the month alone leaves the
 * trailing cells empty for no reason a reader could work out.
 */
export function gridWindow(year, month) {
    const from = gridStart(year, month);

    return { from, to: addDays(from, WEEKS_SHOWN * 7) };
}

/**
 * The grid's days, in order, six weeks of seven.
 *
 * Always six rows. A month needs four, five or six depending on where it starts,
 * and a grid that changes height as you page through the year moves everything
 * under it - the buttons included. A stable height costs one mostly-empty row in
 * February and buys a page that does not jump.
 */
export function monthGrid(year, month) {
    const start = gridStart(year, month);

    return Array.from({ length: WEEKS_SHOWN * 7 }, (_, index) => {
        const date = addDays(start, index);

        return {
            date,
            key: date.toISOString().slice(0, 10),
            dayOfMonth: date.getDate(),
            inMonth: date.getMonth() === month,
        };
    });
}

/**
 * Whether an event owns whole days rather than a slot in one.
 *
 * Either it says so, or it runs past midnight. The second half matters: a meeting
 * from 23:00 to 01:00 is not an all-day event but it cannot be drawn as a dot in
 * one cell either.
 */
export function spansDays(event) {
    if (event.allDay) {
        return true;
    }

    return !sameDay(new Date(event.startAt), new Date(event.endAt));
}

/**
 * Lay the spanning events of one week out in lanes.
 *
 * Greedy, left to right: each bar takes the first lane whose previous bar has
 * already ended. That is the standard allocation and it is what keeps two
 * overlapping runs from being drawn on top of each other.
 *
 * Sorted by start, then by length descending: a longer run started on the same
 * day takes the upper lane, which keeps the long bars together at the top
 * instead of threading between the short ones.
 *
 * Every bar is clipped to the week. An event crossing a Sunday appears in two
 * rows, and each row draws only its own part - `continuesBefore` and
 * `continuesAfter` are what let the ends be flat rather than rounded, so the
 * reader can see it goes on.
 */
export function layOutWeek(weekStart, events) {
    const weekEnd = addDays(weekStart, 7);

    const bars = events
        .filter(spansDays)
        .map((event) => {
            const start = new Date(event.startAt);
            const end = new Date(event.endAt);

            return { event, start, end };
        })
        .filter(({ start, end }) => start < weekEnd && end > weekStart)
        .map(({ event, start, end }) => {
            const from = Math.max(
                0,
                Math.floor((startOfDay(start) - weekStart) / 86400000),
            );
            // The end is inclusive of the day it falls on: an event ending at
            // 23:59 on Wednesday covers Wednesday, and one ending at 00:00 on
            // Wednesday does not.
            const lastDay = startOfDay(new Date(end.getTime() - 1));
            const to = Math.min(
                6,
                Math.floor((lastDay - weekStart) / 86400000),
            );

            return {
                event,
                from,
                span: Math.max(1, to - from + 1),
                continuesBefore: start < weekStart,
                continuesAfter: end > weekEnd,
                length: end - start,
            };
        })
        .sort((a, b) => a.from - b.from || b.length - a.length);

    const laneEnds = [];
    const placed = [];

    for (const bar of bars) {
        let lane = laneEnds.findIndex((end) => end <= bar.from);
        if (-1 === lane) {
            lane = laneEnds.length;
        }

        laneEnds[lane] = bar.from + bar.span;
        placed.push({ ...bar, lane });
    }

    const drawn = placed.filter((bar) => bar.lane < MAX_LANES);

    return {
        bars: drawn,
        /**
         * How many bars per day did not fit, so a cell can say "+2" against the
         * right day rather than the week saying it once in the wrong place.
         */
        hiddenPerDay: countHidden(
            placed.filter((bar) => bar.lane >= MAX_LANES),
        ),
        lanesPerDay: countLanes(drawn),
    };
}

/**
 * How many lanes each day has to leave room for.
 *
 * Per day and not per week, which is the whole point. A cell that reserves space
 * for every bar in its week gets pushed down by a run of leave three days away:
 * the same event drawn on the 14th sat lower than it did in a week with nothing
 * crossing it, for no reason a reader could see.
 *
 * The count is the highest lane in use plus one, not the number of bars covering
 * the day. A bar in lane 1 is drawn one lane down whether or not lane 0 is
 * occupied here, so a day under it has two lanes' worth of height above it.
 */
function countLanes(bars) {
    const lanes = new Array(7).fill(0);

    for (const bar of bars) {
        for (let day = bar.from; day < bar.from + bar.span && day < 7; ++day) {
            lanes[day] = Math.max(lanes[day], bar.lane + 1);
        }
    }

    return lanes;
}

function countHidden(bars) {
    const counts = new Array(7).fill(0);
    for (const bar of bars) {
        for (let day = bar.from; day < bar.from + bar.span && day < 7; ++day) {
            counts[day] += 1;
        }
    }

    return counts;
}

/**
 * The timed events of one day, in order.
 *
 * Only the ones that fit in a cell: anything spanning days is a bar, and drawing
 * it here as well would say the same thing twice in the same row.
 */
export function timedEventsOn(date, events) {
    return events
        .filter(
            (event) =>
                !spansDays(event) && sameDay(new Date(event.startAt), date),
        )
        .sort((a, b) => new Date(a.startAt) - new Date(b.startAt));
}
