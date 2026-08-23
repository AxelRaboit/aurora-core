import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import CalendarTimeGrid from "./CalendarTimeGrid.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key, d: () => "09:00" }),
}));

function mountGrid(props = {}) {
    return mount(CalendarTimeGrid, {
        props: {
            anchor: new Date(2026, 7, 17),
            view: "week",
            events: [],
            reminders: [],
            ...props,
        },
    });
}

/** The hour columns, which are the only clickable boxes in the grid. */
function columns(wrapper) {
    return wrapper
        .findAll(".cursor-pointer")
        .filter((el) => el.attributes("style")?.includes("72rem"));
}

describe("CalendarTimeGrid", () => {
    it("draws seven columns for a week and one for a day", () => {
        expect(columns(mountGrid())).toHaveLength(7);
        expect(columns(mountGrid({ view: "day" }))).toHaveLength(1);
    });

    /**
     * Every hour, all the way down.
     *
     * Worth asserting because the grid looked wrong on screen and the structure
     * turned out to be right - the lines were all there and almost invisible. This
     * keeps the count honest if the layout is ever rewritten.
     */
    it("rules every hour of every column", () => {
        const lines = columns(mountGrid())[0]
            .findAll("div")
            .filter((el) => el.attributes("class")?.includes("border-b"));

        expect(lines).toHaveLength(24);
        expect(lines[0].attributes("style")).toContain("top: 0rem");
        expect(lines.at(-1).attributes("style")).toContain("top: 69rem");
    });

    /**
     * At full strength, like the vertical separators.
     *
     * `--color-line` is already a dark slate in the dark theme, so at 60% the
     * rules read as nothing and the grid looked like seven empty columns with tick
     * marks beside them.
     */
    it("rules the hours as visibly as it separates the days", () => {
        const line = columns(mountGrid())[0]
            .findAll("div")
            .find((el) => el.attributes("class")?.includes("border-b"));

        expect(line.attributes("class")).toContain("border-line");
        expect(line.attributes("class")).not.toContain("border-line/");
    });

    it("gives the hour gutter no rules of its own", () => {
        // They turned each hour into a tick mark stopping at the labels. Google
        // starts the rule after the gutter too.
        const gutterBoxes = mountGrid().findAll(".w-11 > div");

        expect(gutterBoxes).toHaveLength(24);
        for (const box of gutterBoxes) {
            expect(box.attributes("class")).not.toContain("border-b");
        }
    });
});
