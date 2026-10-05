import { describe, expect, it } from "vitest";
import {
    ENDS,
    PRESETS,
    WEEKDAYS,
    blankRecurrence,
    formForPreset,
    fromRrule,
    toRrule,
} from "./recurrenceRule.js";

/** A Monday, so a weekly preset has a predictable day to anchor on. */
const MONDAY = new Date(2026, 8, 7, 9, 0);
const THURSDAY = new Date(2026, 8, 10, 9, 0);

describe("recurrenceRule presets", () => {
    it("does not repeat by default", () => {
        expect(blankRecurrence().preset).toBe("none");
        expect(toRrule(blankRecurrence())).toBeNull();
    });

    /**
     * "Every week" means every week on that day.
     *
     * Anchored on the date being repeated rather than a default, or a Thursday
     * meeting set to repeat weekly would land on Mondays.
     */
    it("anchors a weekly preset on the day it starts", () => {
        expect(toRrule(formForPreset("weekly", MONDAY))).toBe(
            "FREQ=WEEKLY;BYDAY=MO",
        );
        expect(toRrule(formForPreset("weekly", THURSDAY))).toBe(
            "FREQ=WEEKLY;BYDAY=TH",
        );
    });

    it("writes the other three frequencies plainly", () => {
        expect(toRrule(formForPreset("daily", MONDAY))).toBe("FREQ=DAILY");
        expect(toRrule(formForPreset("monthly", MONDAY))).toBe("FREQ=MONTHLY");
        expect(toRrule(formForPreset("yearly", MONDAY))).toBe("FREQ=YEARLY");
    });

    it("offers every preset and every way of ending", () => {
        expect(PRESETS).toEqual([
            "none",
            "daily",
            "weekly",
            "monthly",
            "yearly",
            "custom",
        ]);
        expect(ENDS).toEqual(["never", "until", "count"]);
        expect(WEEKDAYS[0]).toBe("MO");
    });
});

describe("recurrenceRule writing", () => {
    it("leaves out an interval of one", () => {
        // The standard's default. Writing it makes every rule longer for nothing.
        expect(
            toRrule({ ...formForPreset("weekly", MONDAY), interval: 1 }),
        ).toBe("FREQ=WEEKLY;BYDAY=MO");
        expect(
            toRrule({
                ...formForPreset("weekly", MONDAY),
                preset: "custom",
                interval: 2,
            }),
        ).toBe("FREQ=WEEKLY;INTERVAL=2;BYDAY=MO");
    });

    it("writes the days in the standard's order whatever order they were ticked", () => {
        // So two forms that mean the same thing produce the same string, which is
        // what lets a round trip land back on the same preset.
        const form = {
            ...blankRecurrence(),
            preset: "custom",
            freq: "WEEKLY",
            byDay: ["FR", "MO", "WE"],
        };

        expect(toRrule(form)).toBe("FREQ=WEEKLY;BYDAY=MO,WE,FR");
    });

    it("writes a count", () => {
        const form = {
            ...formForPreset("weekly", MONDAY),
            preset: "custom",
            end: "count",
            count: 4,
        };

        expect(toRrule(form)).toBe("FREQ=WEEKLY;BYDAY=MO;COUNT=4");
    });

    it("ends on the end of the chosen day", () => {
        // A series told to stop on the 31st includes the 31st. UNTIL is an instant
        // and the field is a date, so the difference has to be decided somewhere.
        const form = {
            ...formForPreset("daily", MONDAY),
            preset: "custom",
            end: "until",
            until: "2026-12-31",
        };
        const rrule = toRrule(form);

        expect(rrule).toContain("FREQ=DAILY");
        expect(rrule).toMatch(/UNTIL=2026123\dT\d{6}Z/);
    });

    it("ignores an end nobody filled in", () => {
        expect(
            toRrule({
                ...formForPreset("daily", MONDAY),
                preset: "custom",
                end: "until",
                until: "",
            }),
        ).toBe("FREQ=DAILY");
        expect(
            toRrule({
                ...formForPreset("daily", MONDAY),
                preset: "custom",
                end: "count",
                count: 0,
            }),
        ).toBe("FREQ=DAILY");
    });
});

describe("recurrenceRule reading", () => {
    it("reads nothing as does-not-repeat", () => {
        expect(fromRrule(null, MONDAY).preset).toBe("none");
        expect(fromRrule("", MONDAY).preset).toBe("none");
    });

    /**
     * A rule that says no more than a preset does reopens on that preset.
     *
     * Otherwise every recurring event reopens in the custom panel, and the reader
     * cannot tell what was actually set from what the form defaulted to.
     */
    it("lands back on the preset it came from", () => {
        for (const preset of ["daily", "weekly", "monthly", "yearly"]) {
            const rrule = toRrule(formForPreset(preset, MONDAY));

            expect(fromRrule(rrule, MONDAY).preset).toBe(preset);
        }
    });

    it("reads anything richer than a preset as custom", () => {
        // An interval, several days, or an end: each is something a preset cannot
        // express, and showing one would silently simplify the rule on the next
        // save.
        expect(
            fromRrule("FREQ=WEEKLY;INTERVAL=2;BYDAY=MO", MONDAY).preset,
        ).toBe("custom");
        expect(fromRrule("FREQ=WEEKLY;BYDAY=MO,WE", MONDAY).preset).toBe(
            "custom",
        );
        expect(fromRrule("FREQ=WEEKLY;BYDAY=MO;COUNT=4", MONDAY).preset).toBe(
            "custom",
        );
    });

    it("reads a weekly rule on another day as custom rather than as weekly", () => {
        // The rule says Wednesday and the event starts on a Monday: it repeats,
        // but not the way "every week" would.
        expect(fromRrule("FREQ=WEEKLY;BYDAY=WE", MONDAY).preset).toBe("custom");
    });

    it("survives a round trip through a custom rule", () => {
        const rrule = "FREQ=WEEKLY;INTERVAL=3;BYDAY=MO,TH;COUNT=12";

        expect(toRrule(fromRrule(rrule, MONDAY))).toBe(rrule);
    });

    it("reads a count and an until back into the form", () => {
        const withCount = fromRrule("FREQ=DAILY;COUNT=5", MONDAY);
        expect(withCount.end).toBe("count");
        expect(withCount.count).toBe(5);

        const withUntil = fromRrule(
            "FREQ=DAILY;UNTIL=20261231T225959Z",
            MONDAY,
        );
        expect(withUntil.end).toBe("until");
        expect(withUntil.until).toBe("2026-12-31");
    });

    it("falls back on a frequency it does not know rather than throwing", () => {
        // A rule written by hand, or by a version of this that offered more.
        expect(fromRrule("FREQ=CHAQUE_MARDI", MONDAY).freq).toBe("WEEKLY");
    });
});
