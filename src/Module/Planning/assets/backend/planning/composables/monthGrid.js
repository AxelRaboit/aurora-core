/**
 * The date arithmetic behind a month view, with no Vue in it.
 *
 * Kept as plain functions because this is the part that is worth testing on its
 * own: everything hard about a month grid is here - which days it shows, which
 * events touch which week, and how two events that overlap avoid each other -
 * and none of it needs a component mounted to be wrong.
 *
 * Dates are handled with a plain `Date` and its local getters, which is what keeps
 * this readable - and the screen's chosen display zone is applied *before* anything
 * reaches here. `displayZone.js` rewrites each instant as the wall clock it reads
 * in that zone, so every comparison below is already in it without knowing.
 *
 * That is the resolution of what this note used to say was owed. Reading "the
 * calendar's timezone" here was never possible: a grid shows several calendars at
 * once and a "Tuesday" column cannot be Tuesday in two zones. One display zone for
 * the screen can be, and it is what Google does.
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

/**
 * A date as `YYYY-MM-DD`, on the local calendar.
 *
 * Not `toISOString().slice(0, 10)`, which is UTC: east of Greenwich a local
 * midnight is the previous day there, so every key named the day before. It went
 * unnoticed while these were only Vue keys - uniqueness was all they needed - and
 * became a bug the moment a cell had to say which day it stands for.
 *
 * @param {Date} date
 * @returns {string}
 */
export function dayKey(date) {
    const pad = (n) => String(n).padStart(2, "0");

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
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
            key: dayKey(date),
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

/**
 * Everything falling on one day, in the order it happens.
 *
 * Both kinds together and both shapes of event: what a phone shows under the
 * month grid is "this day", not "this day's timed events" - a run of leave and an
 * all-day reminder belong in that list as much as a 14:00 meeting.
 *
 * Sorted by when each thing starts, with all-day items first: they own the whole
 * day, so putting them at 00:00 among the timed ones would be arbitrary precision
 * about something that has none.
 *
 * @param {Date} date
 * @param {Array<object>} events
 * @param {Array<object>} reminders
 * @returns {Array<object>}
 */
export function itemsOn(date, events, reminders) {
    const items = [
        ...events
            .filter((event) => {
                const start = new Date(event.startAt);
                const end = new Date(event.endAt);

                // An event covering this day, not only one starting on it: a run
                // of leave has to appear on every day it covers.
                return (
                    start < addDays(startOfDay(date), 1) &&
                    end > startOfDay(date)
                );
            })
            .map((event) => ({
                kind: "event",
                item: event,
                at: new Date(event.startAt),
                whole: spansDays(event),
            })),
        ...reminders
            .filter((reminder) => sameDay(new Date(reminder.dueAt), date))
            .map((reminder) => ({
                kind: "reminder",
                item: reminder,
                at: new Date(reminder.dueAt),
                whole: Boolean(reminder.allDay),
            })),
    ];

    return items.sort((a, b) => {
        if (a.whole !== b.whole) {
            return a.whole ? -1 : 1;
        }

        return a.at - b.at;
    });
}

/**
 * How many things fall on each day of a week, for the dots a phone draws.
 *
 * A count and not the items: the cell shows at most a few dots and then a number,
 * so it needs to know how many there are far more often than what they are.
 *
 * @param {Array<object>} cells the week's seven cells
 * @param {Array<object>} events
 * @param {Array<object>} reminders
 * @returns {number[]}
 */
export function countsPerDay(cells, events, reminders) {
    return cells.map((cell) => itemsOn(cell.date, events, reminders).length);
}

/**
 * The days of a range that hold something, each with its contents.
 *
 * Empty days are left out, which is what separates an agenda from a grid: the
 * grid's job is to show the shape of the month including its gaps, and the
 * agenda's is to show the sequence without them. A list with "nothing on the
 * 12th" in it would be a grid drawn in one column.
 *
 * @param {Array<{date: Date, key: string}>} cells
 * @param {Array<object>} events
 * @param {Array<object>} reminders
 * @returns {Array<{date: Date, key: string, items: Array<object>}>}
 */
export function agendaDays(cells, events, reminders) {
    return cells
        .map((cell) => ({
            date: cell.date,
            key: cell.key,
            items: itemsOn(cell.date, events, reminders),
        }))
        .filter((day) => day.items.length > 0);
}
