import { describe, expect, it } from "vitest";
import { localIsoDate } from "./localDate.js";

describe("localIsoDate", () => {
    it("reads the date where the reader is, not in UTC", () => {
        // One in the morning, local time, on the 1st of October: still the
        // 30th of September in UTC for anybody east of Greenwich.
        const oneAm = new Date(2026, 9, 1, 1, 0, 0);

        expect(localIsoDate(oneAm)).toBe("2026-10-01");
    });

    it("pads the month and the day", () => {
        expect(localIsoDate(new Date(2026, 0, 5, 12))).toBe("2026-01-05");
    });
});
