import { describe, expect, it } from "vitest";
import { toInstant, toPickerValue } from "./eventTime.js";

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
