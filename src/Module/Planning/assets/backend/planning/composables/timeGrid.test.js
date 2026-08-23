import { describe, expect, it } from "vitest";
import {
    HOURS,
    allDayBand,
    layOutDay,
    nowOffset,
    timeGridWindow,
    visibleDays,
    weekStart,
} from "./timeGrid.js";

/** A local-time event, so the tests read in the zone the grid draws in. */
function event(id, startAt, endAt, extra = {}) {
    return {
        id,
        startAt: new Date(startAt).toISOString(),
        endAt: new Date(endAt).toISOString(),
        allDay: false,
        ...extra,
    };
}

const MONDAY = new Date(2026, 7, 24);

describe("weekStart", () => {
    it("finds the Monday of a midweek day", () => {
        expect(weekStart(new Date(2026, 7, 26)).getDate()).toBe(24);
    });

    it("leaves a Monday alone", () => {
        expect(weekStart(MONDAY).getDate()).toBe(24);
    });

    it("goes back six days on a Sunday rather than forward one", () => {
        // `getDay()` is 0 on Sunday, so the naive subtraction jumps into the next
        // week - the same trap `gridStart` documents in the month view.
        expect(weekStart(new Date(2026, 7, 30)).getDate()).toBe(24);
    });
});

describe("visibleDays", () => {
    it("gives one day for the day view", () => {
        const days = visibleDays(new Date(2026, 7, 26), "day");

        expect(days).toHaveLength(1);
        expect(days[0].getDate()).toBe(26);
    });

    it("gives the whole week from its Monday for the week view", () => {
        const days = visibleDays(new Date(2026, 7, 26), "week");

        expect(days).toHaveLength(7);
        expect(days.map((day) => day.getDate())).toEqual([
            24, 25, 26, 27, 28, 29, 30,
        ]);
    });

    it("asks for exactly what it draws", () => {
        // Unlike the month view, which asks for the trailing cells of the months
        // either side.
        const { from, to } = timeGridWindow(new Date(2026, 7, 26), "week");

        expect(from.getDate()).toBe(24);
        expect(to.getDate()).toBe(31);
    });
});

describe("layOutDay", () => {
    it("places an event by its fraction of the day", () => {
        const [block] = layOutDay(MONDAY, [
            event(1, "2026-08-24T09:00", "2026-08-24T10:00"),
        ]);

        expect(block.top).toBeCloseTo(9 / 24, 6);
        expect(block.height).toBeCloseTo(1 / 24, 6);
        expect(block.columns).toBe(1);
        expect(block.column).toBe(0);
    });

    it("splits the width between two events that overlap", () => {
        const blocks = layOutDay(MONDAY, [
            event(1, "2026-08-24T09:00", "2026-08-24T10:00"),
            event(2, "2026-08-24T09:30", "2026-08-24T10:30"),
        ]);

        expect(blocks.map((block) => block.column)).toEqual([0, 1]);
        expect(blocks.every((block) => 2 === block.columns)).toBe(true);
    });

    it("leaves a lone event full width even when another pair overlaps elsewhere", () => {
        // The reason clustering is its own pass. A single greedy allocation over
        // the whole day would give the 17:00 meeting a half-width column because
        // two events collided at 09:00, hours earlier.
        const blocks = layOutDay(MONDAY, [
            event(1, "2026-08-24T09:00", "2026-08-24T10:00"),
            event(2, "2026-08-24T09:30", "2026-08-24T10:30"),
            event(3, "2026-08-24T17:00", "2026-08-24T18:00"),
        ]);

        const alone = blocks.find((block) => 3 === block.event.id);
        expect(alone.columns).toBe(1);
        expect(alone.column).toBe(0);
    });

    it("reuses a column once the event in it has ended", () => {
        // Three events, but never more than two at once: the third takes the
        // column the first vacated rather than a third of the width.
        const blocks = layOutDay(MONDAY, [
            event(1, "2026-08-24T09:00", "2026-08-24T10:00"),
            event(2, "2026-08-24T09:30", "2026-08-24T11:30"),
            event(3, "2026-08-24T10:00", "2026-08-24T11:00"),
        ]);

        expect(Math.max(...blocks.map((block) => block.columns))).toBe(2);
    });

    it("touching events do not overlap", () => {
        // 10:00 to 11:00 then 11:00 to 12:00 is a back-to-back pair, not a
        // collision, so both keep the full width.
        const blocks = layOutDay(MONDAY, [
            event(1, "2026-08-24T10:00", "2026-08-24T11:00"),
            event(2, "2026-08-24T11:00", "2026-08-24T12:00"),
        ]);

        expect(blocks.every((block) => 1 === block.columns)).toBe(true);
    });

    it("gives a very short event a height it can be seen and clicked at", () => {
        const [block] = layOutDay(MONDAY, [
            event(1, "2026-08-24T09:00", "2026-08-24T09:05"),
        ]);

        expect(block.height).toBeCloseTo(15 / 1440, 6);
    });

    it("leaves multi-day and all-day events to the band", () => {
        // Excluded by the same predicate the month view uses, so nothing is ever
        // drawn twice on one screen.
        const blocks = layOutDay(MONDAY, [
            event(1, "2026-08-24T09:00", "2026-08-24T10:00"),
            event(2, "2026-08-24T00:00", "2026-08-24T23:59", { allDay: true }),
            event(3, "2026-08-24T23:00", "2026-08-25T01:00"),
        ]);

        expect(blocks.map((block) => block.event.id)).toEqual([1]);
    });

    it("ignores events belonging to another day", () => {
        const blocks = layOutDay(MONDAY, [
            event(1, "2026-08-25T09:00", "2026-08-25T10:00"),
        ]);

        expect(blocks).toEqual([]);
    });

    it("draws 24 hour rows", () => {
        expect(HOURS).toHaveLength(24);
        expect(HOURS[0]).toBe(0);
        expect(HOURS[23]).toBe(23);
    });
});

describe("allDayBand", () => {
    const week = visibleDays(MONDAY, "week");

    it("spans an all-day event across the days it covers", () => {
        const [bar] = allDayBand(week, [
            event(1, "2026-08-25T00:00", "2026-08-27T23:59:59", {
                allDay: true,
            }),
        ]);

        expect(bar.from).toBe(1);
        expect(bar.span).toBe(3);
        expect(bar.continuesBefore).toBe(false);
        expect(bar.continuesAfter).toBe(false);
    });

    it("clips an event that started before the range and says so", () => {
        const [bar] = allDayBand(week, [
            event(1, "2026-08-20T00:00", "2026-08-25T23:59:59", {
                allDay: true,
            }),
        ]);

        expect(bar.from).toBe(0);
        expect(bar.span).toBe(2);
        expect(bar.continuesBefore).toBe(true);
    });

    it("clips an event running past the range and says so", () => {
        const [bar] = allDayBand(week, [
            event(1, "2026-08-29T00:00", "2026-09-04T23:59:59", {
                allDay: true,
            }),
        ]);

        expect(bar.from).toBe(5);
        expect(bar.span).toBe(2);
        expect(bar.continuesAfter).toBe(true);
    });

    it("takes an event crossing midnight, which no column can draw", () => {
        const [bar] = allDayBand(week, [
            event(1, "2026-08-24T23:00", "2026-08-25T01:00"),
        ]);

        expect(bar.from).toBe(0);
        expect(bar.span).toBe(2);
    });

    it("ends an event on the day it ends, not the one after", () => {
        // Ending at 00:00 Tuesday covers Monday only. The inclusive end is what
        // stops every all-day event being drawn one day too wide.
        const [bar] = allDayBand(week, [
            event(1, "2026-08-24T00:00", "2026-08-25T00:00", { allDay: true }),
        ]);

        expect(bar.span).toBe(1);
    });

    it("leaves timed events to the columns", () => {
        expect(
            allDayBand(week, [
                event(1, "2026-08-24T09:00", "2026-08-24T10:00"),
            ]),
        ).toEqual([]);
    });

    it("has nothing to place when there are no days", () => {
        expect(
            allDayBand([], [event(1, "2026-08-24T09:00", "2026-08-24T10:00")]),
        ).toEqual([]);
    });
});

describe("nowOffset", () => {
    it("places the line inside today", () => {
        const now = new Date(2026, 7, 24, 12, 0);

        expect(nowOffset(MONDAY, now)).toBeCloseTo(0.5, 6);
    });

    it("has no position on another day", () => {
        // Null and not zero: zero would draw a rule across the top of every other
        // day in the week.
        expect(
            nowOffset(new Date(2026, 7, 25), new Date(2026, 7, 24, 12, 0)),
        ).toBeNull();
    });
});
