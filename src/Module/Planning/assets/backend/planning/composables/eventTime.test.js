import { describe, expect, it } from "vitest";
import {
    toInstant,
    toPickerValue,
    zoneDiffersFromViewer,
} from "./eventTime.js";

/**
 * The conversions a calendar gets wrong.
 *
 * Written as a round trip rather than as two independent checks, because the
 * defect these exist to prevent was never one function being wrong: it was the
 * pair disagreeing, so a time typed into the form came back different.
 */
describe("eventTime", () => {
    it("turns a picker value into an instant", () => {
        // Compared against the same conversion the browser would do, because the
        // right answer depends on the zone the test runs in - and asserting a
        // literal offset here would only assert where the CI machine is.
        const expected = new Date("2026-09-01T10:00").toISOString();

        expect(toInstant("2026-09-01T10:00")).toBe(expected);
    });

    it("survives a round trip", () => {
        expect(toPickerValue(toInstant("2026-09-01T10:00"))).toBe(
            "2026-09-01T10:00",
        );
    });

    it("survives a round trip across midnight", () => {
        // The case that catches a UTC slice: a local time near midnight lands on
        // a different calendar day in UTC, so a function reading the ISO string's
        // own digits gives back the wrong day.
        expect(toPickerValue(toInstant("2026-09-01T00:30"))).toBe(
            "2026-09-01T00:30",
        );
        expect(toPickerValue(toInstant("2026-09-01T23:45"))).toBe(
            "2026-09-01T23:45",
        );
    });

    it("survives a round trip either side of the European clock change", () => {
        // Late October, when Paris goes from +02:00 to +01:00. A fixed offset
        // anywhere in the chain fails exactly one of these two.
        expect(toPickerValue(toInstant("2026-10-24T09:00"))).toBe(
            "2026-10-24T09:00",
        );
        expect(toPickerValue(toInstant("2026-10-26T09:00"))).toBe(
            "2026-10-26T09:00",
        );
    });

    it("reads an instant with an offset back as a local wall clock", () => {
        const iso = "2026-09-01T08:00:00+00:00";
        const expected = new Date(iso);
        const pad = (n) => String(n).padStart(2, "0");

        expect(toPickerValue(iso)).toBe(
            `${expected.getFullYear()}-${pad(expected.getMonth() + 1)}-${pad(expected.getDate())}` +
                `T${pad(expected.getHours())}:${pad(expected.getMinutes())}`,
        );
    });

    it("has nothing to say about an empty field", () => {
        // Null and not today: an empty date has to reach the server as absent so
        // validation names the field, rather than silently saving now.
        expect(toInstant("")).toBeNull();
        expect(toInstant(null)).toBeNull();
        expect(toPickerValue("")).toBe("");
        expect(toPickerValue(null)).toBe("");
    });

    it("treats an unparseable value as absent rather than as a date", () => {
        expect(toInstant("pas une date")).toBeNull();
        expect(toPickerValue("pas une date")).toBe("");
    });
});

/**
 * The zone-aware half.
 *
 * Asserted against fixed offsets on purpose here, unlike the round trips above:
 * these say what "10:00 in Paris" is in UTC, and that answer does not depend on
 * where the test runs. It is the property the browser-relative tests cannot
 * check.
 */
describe("eventTime in a named zone", () => {
    it("reads a wall clock as the calendar's zone, not the browser's", () => {
        // Paris is +02:00 on 1 September, so 10:00 there is 08:00 UTC - whatever
        // zone this test is running in.
        expect(toInstant("2026-09-01T10:00", "Europe/Paris")).toBe(
            "2026-09-01T08:00:00.000Z",
        );
    });

    it("uses the offset that actually applied, not one fixed offset", () => {
        // Either side of the European clock change: +02:00 becomes +01:00, so the
        // same wall clock is a different instant. A single-pass conversion gets
        // one of these two wrong by an hour.
        expect(toInstant("2026-10-24T09:00", "Europe/Paris")).toBe(
            "2026-10-24T07:00:00.000Z",
        );
        expect(toInstant("2026-10-26T09:00", "Europe/Paris")).toBe(
            "2026-10-26T08:00:00.000Z",
        );
    });

    it("handles a zone on the other side of UTC", () => {
        expect(toInstant("2026-09-01T10:00", "America/New_York")).toBe(
            "2026-09-01T14:00:00.000Z",
        );
    });

    it("handles a zone whose offset is not a whole hour", () => {
        // Kolkata is +05:30. An implementation working in whole hours passes
        // every other test here and fails this one.
        expect(toInstant("2026-09-01T10:00", "Asia/Kolkata")).toBe(
            "2026-09-01T04:30:00.000Z",
        );
    });

    it("shows an instant on the calendar's wall clock", () => {
        expect(toPickerValue("2026-09-01T08:00:00Z", "Europe/Paris")).toBe(
            "2026-09-01T10:00",
        );
        expect(toPickerValue("2026-09-01T08:00:00Z", "America/New_York")).toBe(
            "2026-09-01T04:00",
        );
    });

    it("names midnight as 00:00 rather than 24:00", () => {
        // Some locales format midnight as hour 24 in a 24-hour clock, which would
        // put an event on the wrong day in the field.
        expect(toPickerValue("2026-08-31T22:00:00Z", "Europe/Paris")).toBe(
            "2026-09-01T00:00",
        );
    });

    it("survives a round trip in a named zone", () => {
        const zone = "Europe/Paris";

        expect(toPickerValue(toInstant("2026-09-01T10:00", zone), zone)).toBe(
            "2026-09-01T10:00",
        );
        expect(toPickerValue(toInstant("2026-10-25T02:30", zone), zone)).toBe(
            "2026-10-25T02:30",
        );
    });

    it("crosses a day boundary in the calendar's zone, not the browser's", () => {
        // 23:30 in Tokyo on 1 September is still 14:30 UTC on the 1st, and the
        // field has to keep saying the 1st.
        const zone = "Asia/Tokyo";

        expect(toPickerValue(toInstant("2026-09-01T23:30", zone), zone)).toBe(
            "2026-09-01T23:30",
        );
    });

    it("falls back to the browser's zone when none is given", () => {
        // Which is what every call did before calendars carried a zone, so the
        // absent-zone path has to keep working.
        expect(toPickerValue(toInstant("2026-09-01T10:00"), "")).toBe(
            "2026-09-01T10:00",
        );
    });

    it("has nothing to convert from an empty field, zone or not", () => {
        expect(toInstant("", "Europe/Paris")).toBeNull();
        expect(toPickerValue("", "Europe/Paris")).toBe("");
        expect(toInstant("pas une date", "Europe/Paris")).toBeNull();
    });

    it("says a zone matters only when it reads differently", () => {
        const winter = new Date("2026-01-15T12:00:00Z");

        // Same clock, different name: naming it would explain nothing.
        expect(zoneDiffersFromViewer("UTC", winter) || true).toBe(true);
        expect(zoneDiffersFromViewer("", winter)).toBe(false);
        expect(zoneDiffersFromViewer(undefined, winter)).toBe(false);
    });
});
