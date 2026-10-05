import { describe, expect, it } from "vitest";
import { formatCalendarEntries, parseCalendarEntries } from "./calendarData.js";

describe("calendarData", () => {
    it("reads one entry per line, bars or semicolons", () => {
        expect(
            parseCalendarEntries(
                "2026-11-03 | Carrousel | Les coulisses\n\n2026-11-05 ; Réel ; Une journée",
            ),
        ).toEqual([
            { date: "2026-11-03", kind: "Carrousel", title: "Les coulisses" },
            { date: "2026-11-05", kind: "Réel", title: "Une journée" },
        ]);
    });

    it("keeps a bar written inside the subject", () => {
        expect(
            parseCalendarEntries("2026-11-03 | Post | Avant | après")[0].title,
        ).toBe("Avant | après");
    });

    it("writes the entries back and drops a blank row", () => {
        expect(
            formatCalendarEntries([
                {
                    date: "2026-11-03",
                    kind: " Carrousel ",
                    title: "Les coulisses",
                },
                { date: "", kind: "", title: "" },
            ]),
        ).toBe("2026-11-03 | Carrousel | Les coulisses");
    });

    it("round-trips what it reads", () => {
        const code =
            "2026-11-03 | Carrousel | Les coulisses\n2026-11-05 | Réel | Une journée";
        expect(formatCalendarEntries(parseCalendarEntries(code))).toBe(code);
    });
});
