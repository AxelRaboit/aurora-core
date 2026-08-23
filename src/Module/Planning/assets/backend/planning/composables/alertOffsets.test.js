import { describe, expect, it } from "vitest";
import {
    DEFAULT_ALERT_OFFSET,
    ALERT_OFFSETS,
    alertLabel,
    toggleAlert,
} from "./alertOffsets.js";

describe("alertOffsets", () => {
    it("adds an offset and keeps the list sorted", () => {
        // Sorted on the way out so the chips do not reorder as they are toggled,
        // which would move the one under the reader's cursor.
        expect(toggleAlert([60], 10)).toEqual([10, 60]);
        expect(toggleAlert([10, 60], 30)).toEqual([10, 30, 60]);
    });

    it("removes an offset already on", () => {
        expect(toggleAlert([10, 30, 60], 30)).toEqual([10, 60]);
    });

    it("returns a new list rather than mutating the one it was given", () => {
        // Vue only sees the change because the caller assigns the result; a
        // mutation in place would toggle the chip and redraw nothing.
        const before = [30];
        const after = toggleAlert(before, 60);

        expect(before).toEqual([30]);
        expect(after).not.toBe(before);
    });

    it("empties down to nothing", () => {
        expect(toggleAlert([30], 30)).toEqual([]);
    });

    it("names an offset through one key per value", () => {
        const t = (key) => key;

        expect(alertLabel(0, t)).toBe("backend.plannings.alerts.offsets.0");
        expect(alertLabel(10080, t)).toBe(
            "backend.plannings.alerts.offsets.10080",
        );
    });

    it("offers a default the list contains", () => {
        expect(ALERT_OFFSETS).toContain(DEFAULT_ALERT_OFFSET);
    });
});
