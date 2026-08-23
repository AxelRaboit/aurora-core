import { describe, expect, it } from "vitest";
import {
    ALERT_OFFSETS,
    CUSTOM,
    DEFAULT_ALERT_OFFSET,
    alertLabel,
    alertOptions,
    blankRow,
    fromRow,
    toRow,
} from "./alertOffsets.js";

const t = (key) => key;

describe("alertOffsets", () => {
    it("offers the presets and a custom option, in that order", () => {
        const options = alertOptions(t);

        expect(options).toHaveLength(ALERT_OFFSETS.length + 1);
        expect(options.at(-1).value).toBe(CUSTOM);
    });

    it("names an offset through one key per value", () => {
        expect(alertLabel(0, t)).toBe("backend.plannings.alerts.offsets.0");
        expect(alertLabel(10080, t)).toBe(
            "backend.plannings.alerts.offsets.10080",
        );
    });

    it("offers a default the list contains", () => {
        expect(ALERT_OFFSETS).toContain(DEFAULT_ALERT_OFFSET);
        expect(blankRow().choice).toBe(DEFAULT_ALERT_OFFSET);
    });

    it("marks custom with a string, not a number", () => {
        // So it cannot collide with an offset however the menu grows, and so a
        // row reads as custom rather than as a magic value.
        expect(typeof CUSTOM).toBe("string");
        expect(ALERT_OFFSETS).not.toContain(CUSTOM);
    });
});

describe("alert rows", () => {
    it("reads a relative alert as its offset", () => {
        expect(toRow({ minutes: 30, at: null })).toEqual({
            choice: 30,
            at: null,
        });
    });

    it("reads a pinned alert as custom, keeping its moment", () => {
        expect(
            toRow({ minutes: null, at: "2026-09-01T07:00:00+00:00" }),
        ).toEqual({
            choice: CUSTOM,
            at: "2026-09-01T07:00:00+00:00",
        });
    });

    it("sends a relative row as an offset", () => {
        expect(fromRow({ choice: 60, at: null })).toEqual({
            minutes: 60,
            at: null,
        });
    });

    it("sends a custom row as a moment", () => {
        expect(
            fromRow({ choice: CUSTOM, at: "2026-09-01T07:00:00+00:00" }),
        ).toEqual({
            minutes: null,
            at: "2026-09-01T07:00:00+00:00",
        });
    });

    it("sends nothing for a custom row with no moment chosen yet", () => {
        // The reader opened the picker and has not picked. Inventing a time would
        // be worse than the row not existing until they do.
        expect(fromRow({ choice: CUSTOM, at: null })).toBeNull();
        expect(fromRow({ choice: CUSTOM, at: "" })).toBeNull();
    });

    it("survives a round trip either way", () => {
        expect(fromRow(toRow({ minutes: 15, at: null }))).toEqual({
            minutes: 15,
            at: null,
        });

        const pinned = { minutes: null, at: "2026-09-01T07:00:00+00:00" };
        expect(fromRow(toRow(pinned))).toEqual(pinned);
    });

    it("keeps zero as an offset rather than losing it to a falsy test", () => {
        // "At the start" is offset 0, and any `row.choice ? ... : ...` in this
        // path would read it as absent.
        expect(toRow({ minutes: 0, at: null })).toEqual({
            choice: 0,
            at: null,
        });
        expect(fromRow({ choice: 0, at: null })).toEqual({
            minutes: 0,
            at: null,
        });
    });
});
