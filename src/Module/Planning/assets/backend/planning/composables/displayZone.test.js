import { describe, expect, it } from "vitest";
import {
    fromDisplay,
    isKnownZone,
    toDisplay,
    toDisplayRow,
    viewerZone,
} from "./displayZone.js";

/**
 * The two conversions the whole screen rests on.
 *
 * Asserted against fixed answers rather than round trips alone: these say what
 * "10:00 in Tokyo" is, and that does not depend on where the test runs - which is
 * the property a round trip cannot check.
 */
describe("toDisplay", () => {
    it("rewrites an instant as the wall clock of the zone", () => {
        // 08:00 UTC is 10:00 in Paris and 17:00 in Tokyo.
        expect(toDisplay("2026-09-01T08:00:00Z", "Europe/Paris")).toBe(
            "2026-09-01T10:00:00",
        );
        expect(toDisplay("2026-09-01T08:00:00Z", "Asia/Tokyo")).toBe(
            "2026-09-01T17:00:00",
        );
    });

    it("crosses a day boundary when the zone does", () => {
        // 23:00 UTC is already the next morning in Tokyo, and the grid has to draw
        // it on that day.
        expect(toDisplay("2026-09-01T23:00:00Z", "Asia/Tokyo")).toBe(
            "2026-09-02T08:00:00",
        );
        expect(toDisplay("2026-09-01T02:00:00Z", "America/Los_Angeles")).toBe(
            "2026-08-31T19:00:00",
        );
    });

    it("says 00:00 for midnight rather than 24:00", () => {
        // Some locales format midnight as hour 24 in a 24-hour clock, which would
        // put an event on the wrong day.
        expect(toDisplay("2026-08-31T22:00:00Z", "Europe/Paris")).toBe(
            "2026-09-01T00:00:00",
        );
    });

    it("carries an unparseable value through rather than inventing one", () => {
        expect(toDisplay("pas une date", "Europe/Paris")).toBe("pas une date");
    });

    it("has no offset in what it produces", () => {
        // That is the whole trick: `Date` parses a datetime with no offset in the
        // browser's own zone, so the local getters the grids use read the display
        // zone's fields.
        expect(toDisplay("2026-09-01T08:00:00Z", "Asia/Tokyo")).not.toMatch(
            /[Z+]/,
        );
    });
});

describe("fromDisplay", () => {
    it("reads a wall clock back as the instant it names", () => {
        expect(
            fromDisplay(new Date(2026, 8, 1, 10, 0, 0), "Europe/Paris"),
        ).toBe("2026-09-01T08:00:00.000Z");
        expect(fromDisplay(new Date(2026, 8, 1, 17, 0, 0), "Asia/Tokyo")).toBe(
            "2026-09-01T08:00:00.000Z",
        );
    });

    it("uses the offset that actually applied, not one fixed offset", () => {
        // Either side of the European clock change. A single-pass conversion gets
        // one of these two wrong by an hour.
        expect(
            fromDisplay(new Date(2026, 9, 24, 9, 0, 0), "Europe/Paris"),
        ).toBe("2026-10-24T07:00:00.000Z");
        expect(
            fromDisplay(new Date(2026, 9, 26, 9, 0, 0), "Europe/Paris"),
        ).toBe("2026-10-26T08:00:00.000Z");
    });

    it("handles a zone whose offset is not a whole hour", () => {
        expect(
            fromDisplay(new Date(2026, 8, 1, 10, 0, 0), "Asia/Kolkata"),
        ).toBe("2026-09-01T04:30:00.000Z");
    });

    it("survives a round trip in any of these zones", () => {
        for (const zone of [
            "Europe/Paris",
            "Asia/Tokyo",
            "America/Los_Angeles",
            "Asia/Kolkata",
            "UTC",
        ]) {
            const instant = "2026-09-01T08:00:00.000Z";

            expect(fromDisplay(new Date(toDisplay(instant, zone)), zone)).toBe(
                instant,
            );
        }
    });
});

describe("toDisplayRow", () => {
    it("shifts the named fields and keeps the true instants", () => {
        // The kept originals are what anything that writes uses. A shifted value is
        // a lie about which instant it is, so it must never reach the server.
        const row = toDisplayRow(
            {
                id: 1,
                startAt: "2026-09-01T08:00:00Z",
                endAt: "2026-09-01T09:00:00Z",
            },
            "Asia/Tokyo",
            ["startAt", "endAt"],
        );

        expect(row.startAt).toBe("2026-09-01T17:00:00");
        expect(row.realStartAt).toBe("2026-09-01T08:00:00Z");
        expect(row.realEndAt).toBe("2026-09-01T09:00:00Z");
        expect(row.id).toBe(1);
    });

    it("leaves an absent field alone", () => {
        const row = toDisplayRow({ id: 1, dueAt: null }, "Europe/Paris", [
            "dueAt",
        ]);

        expect(row.dueAt).toBeNull();
        expect(row.realDueAt).toBeUndefined();
    });
});

describe("isKnownZone", () => {
    it("accepts a zone this runtime can resolve", () => {
        expect(isKnownZone("Europe/Paris")).toBe(true);
        expect(isKnownZone(viewerZone())).toBe(true);
    });

    /**
     * A stored name can outlive a browser update or come from another machine, and
     * an unresolvable one makes every `Intl` call throw - which would empty the
     * calendar rather than misdate it.
     */
    it("refuses one it cannot", () => {
        expect(isKnownZone("Mars/Olympus_Mons")).toBe(false);
        expect(isKnownZone("")).toBe(false);
        expect(isKnownZone(null)).toBe(false);
    });
});
