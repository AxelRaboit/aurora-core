import { describe, it, expect } from "vitest";
import {
    MAX_LANES,
    WEEKS_SHOWN,
    gridStart,
    gridWindow,
    layOutWeek,
    monthGrid,
    sameDay,
    spansDays,
    timedEventsOn,
} from "./monthGrid.js";

const iso = (s) => new Date(s).toISOString();

function event(startAt, endAt, extra = {}) {
    return {
        id: `${startAt}->${endAt}`,
        startAt: iso(startAt),
        endAt: iso(endAt),
        allDay: false,
        ...extra,
    };
}

describe("gridStart", () => {
    // August 2026 opens on a Saturday, so the grid opens on Monday 27 July.
    it("backs up to the Monday on or before the first", () => {
        expect(gridStart(2026, 7).getDate()).toBe(27);
        expect(gridStart(2026, 7).getMonth()).toBe(6);
    });

    /**
     * The case a naive `getDay() - 1` breaks: Sunday is 0, so subtracting one
     * jumps *forward* six days and the grid starts after the month it draws.
     */
    it("handles a month that opens on a Sunday", () => {
        // November 2026 opens on a Sunday.
        const start = gridStart(2026, 10);

        expect(start.getDay()).toBe(1);
        expect(start.getDate()).toBe(26);
        expect(start.getMonth()).toBe(9);
    });

    it("keeps a month that opens on a Monday where it is", () => {
        // June 2026 opens on a Monday.
        expect(gridStart(2026, 5).getDate()).toBe(1);
        expect(gridStart(2026, 5).getMonth()).toBe(5);
    });
});

describe("monthGrid", () => {
    it("always draws six weeks, so the page does not change height", () => {
        for (const month of [1, 5, 7, 10]) {
            expect(monthGrid(2026, month)).toHaveLength(WEEKS_SHOWN * 7);
        }
    });

    it("marks the days that belong to another month", () => {
        const cells = monthGrid(2026, 7);

        expect(cells[0].inMonth).toBe(false);
        expect(cells[0].dayOfMonth).toBe(27);
        expect(cells.filter((c) => c.inMonth)).toHaveLength(31);
    });
});

describe("gridWindow", () => {
    /**
     * The window is the grid and not the month: a month view shows days either
     * side, and asking for the month alone leaves those cells empty for no reason
     * a reader could work out.
     */
    it("covers the whole grid rather than the month", () => {
        const { from, to } = gridWindow(2026, 7);

        expect(from.getDate()).toBe(27);
        expect(from.getMonth()).toBe(6);
        expect(Math.round((to - from) / 86400000)).toBe(WEEKS_SHOWN * 7);
    });
});

describe("spansDays", () => {
    it("is true for an all-day event", () => {
        expect(
            spansDays(
                event("2026-08-23T00:00", "2026-08-23T23:59", { allDay: true }),
            ),
        ).toBe(true);
    });

    it("is false for a meeting inside one day", () => {
        expect(spansDays(event("2026-08-23T14:00", "2026-08-23T15:30"))).toBe(
            false,
        );
    });

    /**
     * A meeting from 23:00 to 01:00 is not an all-day event, and it cannot be
     * drawn as a dot in one cell either.
     */
    it("is true for anything crossing midnight", () => {
        expect(spansDays(event("2026-08-23T23:00", "2026-08-24T01:00"))).toBe(
            true,
        );
    });
});

describe("layOutWeek", () => {
    const monday = new Date(2026, 7, 3);

    it("places a run across the days it covers", () => {
        const { bars } = layOutWeek(monday, [
            event("2026-08-04T00:00", "2026-08-06T23:59", { allDay: true }),
        ]);

        expect(bars).toHaveLength(1);
        expect(bars[0].from).toBe(1);
        expect(bars[0].span).toBe(3);
        expect(bars[0].lane).toBe(0);
    });

    /**
     * The end is inclusive of the day it falls on. An event ending at midnight
     * does not cover the day it lands on - that is what stops a two-day event
     * looking like a three-day one.
     */
    it("does not claim a day it only ends at midnight of", () => {
        const { bars } = layOutWeek(monday, [
            event("2026-08-03T00:00", "2026-08-05T00:00", { allDay: true }),
        ]);

        expect(bars[0].span).toBe(2);
    });

    it("stacks two overlapping runs into separate lanes", () => {
        const { bars } = layOutWeek(monday, [
            event("2026-08-03T00:00", "2026-08-05T23:59", { allDay: true }),
            event("2026-08-04T00:00", "2026-08-06T23:59", { allDay: true }),
        ]);

        expect(bars.map((b) => b.lane)).toEqual([0, 1]);
    });

    /** Greedy allocation: a lane is free again the moment its bar has ended. */
    it("reuses a lane once its bar has finished", () => {
        const { bars } = layOutWeek(monday, [
            event("2026-08-03T00:00", "2026-08-03T23:59", { allDay: true }),
            event("2026-08-05T00:00", "2026-08-05T23:59", { allDay: true }),
        ]);

        expect(bars.map((b) => b.lane)).toEqual([0, 0]);
    });

    /**
     * An event crossing a Sunday appears in two rows, each drawing its own part.
     * The flags are what let the cut ends be flat, so the reader can see it goes on.
     */
    it("clips a run to the week and says which side it continues on", () => {
        const { bars } = layOutWeek(monday, [
            event("2026-08-01T00:00", "2026-08-12T23:59", { allDay: true }),
        ]);

        expect(bars[0].from).toBe(0);
        expect(bars[0].span).toBe(7);
        expect(bars[0].continuesBefore).toBe(true);
        expect(bars[0].continuesAfter).toBe(true);
    });

    it("leaves out a run that misses the week entirely", () => {
        const { bars } = layOutWeek(monday, [
            event("2026-09-01T00:00", "2026-09-03T23:59", { allDay: true }),
        ]);

        expect(bars).toEqual([]);
    });

    /**
     * Past the lane cap the rest are counted per day, so a cell says "+2" against
     * the right day instead of the week saying it once in the wrong place.
     */
    it("counts what did not fit, day by day", () => {
        const many = Array.from({ length: MAX_LANES + 2 }, (_, i) =>
            event("2026-08-04T00:00", "2026-08-05T23:59", {
                allDay: true,
                id: `e${i}`,
            }),
        );

        const { bars, hiddenPerDay } = layOutWeek(monday, many);

        expect(bars).toHaveLength(MAX_LANES);
        expect(hiddenPerDay[1]).toBe(2);
        expect(hiddenPerDay[2]).toBe(2);
        expect(hiddenPerDay[0]).toBe(0);
    });
});

describe("timedEventsOn", () => {
    it("returns one day's timed events in order", () => {
        const day = new Date(2026, 7, 21);
        const found = timedEventsOn(day, [
            event("2026-08-21T19:00", "2026-08-21T21:00"),
            event("2026-08-21T11:00", "2026-08-21T12:00"),
            event("2026-08-22T09:00", "2026-08-22T10:00"),
        ]);

        expect(found).toHaveLength(2);
        expect(new Date(found[0].startAt).getHours()).toBe(11);
    });

    /** A run is already a bar; drawing it here too says it twice in one row. */
    it("leaves out anything that spans days", () => {
        const day = new Date(2026, 7, 21);

        expect(
            timedEventsOn(day, [
                event("2026-08-20T00:00", "2026-08-23T23:59", { allDay: true }),
            ]),
        ).toEqual([]);
    });
});

describe("sameDay", () => {
    it("compares the day and not the instant", () => {
        expect(
            sameDay(new Date(2026, 7, 23, 1), new Date(2026, 7, 23, 23)),
        ).toBe(true);
        expect(
            sameDay(new Date(2026, 7, 23, 23), new Date(2026, 7, 24, 1)),
        ).toBe(false);
    });
});
