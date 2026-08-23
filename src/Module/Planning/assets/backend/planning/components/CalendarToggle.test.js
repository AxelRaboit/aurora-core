import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import CalendarToggle from "./CalendarToggle.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

const CALENDAR = { id: 7, name: "Pro", colourSlot: 3 };

function mountToggle(props = {}) {
    return mount(CalendarToggle, { props: { calendar: CALENDAR, ...props } });
}

describe("CalendarToggle", () => {
    /**
     * The swatch is the only thing saying which calendar a colour belongs to, and
     * it is drawn from the slot rather than a resolved value so it survives a
     * theme switch.
     */
    it("fills the swatch with the calendar's own colour slot", () => {
        const swatch = mountToggle().find("span.rounded");

        expect(swatch.attributes("style")).toContain("var(--chart-cat-3)");
    });

    /**
     * An outline and not a paler fill: the row is already at 40% opacity when
     * hidden, and two shades of faint are not a state anybody can read.
     */
    it("outlines the swatch instead when the calendar is folded away", () => {
        const wrapper = mountToggle({ hidden: true });
        const swatch = wrapper.find("span.rounded");

        expect(swatch.attributes("style")).not.toContain("var(--chart-cat-3)");
        expect(swatch.attributes("style")).toContain("solid");
        expect(wrapper.classes()).toContain("opacity-40");
    });

    it("reports its folded state to a screen reader", () => {
        expect(mountToggle().find("button").attributes("aria-pressed")).toBe(
            "true",
        );
        expect(
            mountToggle({ hidden: true })
                .find("button")
                .attributes("aria-pressed"),
        ).toBe("false");
    });

    it("asks to be toggled by id, and to be edited by object", async () => {
        const wrapper = mountToggle({ canManage: true });

        await wrapper.find("button").trigger("click");
        expect(wrapper.emitted("toggle")).toEqual([[7]]);

        await wrapper.findAll("button")[1].trigger("click");
        expect(wrapper.emitted("edit")).toEqual([[CALENDAR]]);
    });

    /**
     * No number rather than "0".
     *
     * A zero in figures reads as a fact about the calendar; this one is a fact
     * about the month on screen. Nothing at all says "nothing here right now",
     * which is what it means.
     */
    it("draws no count when the range holds nothing", () => {
        expect(mountToggle({ count: 0 }).text()).not.toContain("0");
        expect(mountToggle({ count: 4 }).text()).toContain("4");
    });

    /**
     * The pencil takes the count's place on hover, so the row keeps its width and
     * the names stay lined up. Without the right to manage there is no pencil, so
     * hiding the count on hover would leave a gap that fills with nothing.
     */
    it("only yields the count to a pencil there is room for", () => {
        expect(mountToggle({ count: 4, canManage: true }).html()).toContain(
            "group-hover:hidden",
        );
        expect(
            mountToggle({ count: 4, canManage: false }).html(),
        ).not.toContain("group-hover:hidden");
    });

    /** The bar's shape carries its own border; the sheet's row spans the width. */
    it("takes a border as a pill and none as a row", () => {
        expect(mountToggle({ pill: true }).classes()).toContain("border");
        expect(mountToggle().classes()).not.toContain("border");
    });
});
