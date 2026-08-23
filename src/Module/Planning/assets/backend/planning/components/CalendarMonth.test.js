import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import CalendarMonth from "./CalendarMonth.vue";
import { monthGrid } from "../composables/monthGrid.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({
        t: (key) => key,
        d: (date, opts) => (opts?.weekday ? "lun." : String(date)),
    }),
}));

const CELLS = monthGrid(2026, 7);

function event(id, startAt, endAt, extra = {}) {
    return {
        id,
        title: `Événement ${id}`,
        startAt: new Date(startAt).toISOString(),
        endAt: new Date(endAt).toISOString(),
        allDay: false,
        colourSlot: 1,
        ...extra,
    };
}

function mountMonth(props) {
    return mount(CalendarMonth, {
        props: { cells: CELLS, events: [], ...props },
    });
}

describe("CalendarMonth", () => {
    it("shows titles in the cells on a wide screen", () => {
        const wrapper = mountMonth({
            events: [event(1, "2026-08-24T09:00", "2026-08-24T10:00")],
        });

        expect(wrapper.text()).toContain("Événement 1");
    });

    /**
     * The phone grid is an index, not a list.
     *
     * A cell there is about fifty pixels wide - enough for a day number and a few
     * dots, nothing like enough for a title. Both Google and Apple show the grid
     * as an index and put the contents in a list underneath, which is what
     * `CalendarDayList` is for.
     */
    it("shows no titles when compact", () => {
        const wrapper = mountMonth({
            events: [event(1, "2026-08-24T09:00", "2026-08-24T10:00")],
            compact: true,
        });

        expect(wrapper.text()).not.toContain("Événement 1");
    });

    it("draws a run of days as a bar on a wide screen and not when compact", () => {
        const leave = event(2, "2026-08-24T00:00", "2026-08-27T23:59:59", {
            allDay: true,
            title: "Congés",
        });

        expect(mountMonth({ events: [leave] }).text()).toContain("Congés");
        expect(
            mountMonth({ events: [leave], compact: true }).text(),
        ).not.toContain("Congés");
    });

    /**
     * One gesture cannot honestly mean two things.
     *
     * Wide, a cell already shows what is on it, so tapping it starts something
     * new. Compact, it cannot, so tapping it has to mean "show me this day" -
     * and creating gets its own control in the list's header.
     */
    it("a cell click starts an event on a wide screen", async () => {
        const wrapper = mountMonth({});

        await wrapper.findAll(".grid-cols-7 > div")[7].trigger("click");

        expect(wrapper.emitted("add-on")).toBeTruthy();
        expect(wrapper.emitted("select-day")).toBeFalsy();
    });

    it("a cell click selects the day when compact", async () => {
        const wrapper = mountMonth({ compact: true });

        await wrapper.findAll(".grid-cols-7 > div")[7].trigger("click");

        expect(wrapper.emitted("select-day")).toBeTruthy();
        expect(wrapper.emitted("add-on")).toBeFalsy();
    });
});

describe("dragging in the month grid", () => {
    /**
     * The cell a pointer is over, faked.
     *
     * jsdom has no layout, so `elementFromPoint` always answers null. The
     * component asks the DOM which day it is over rather than dividing a row
     * height, precisely because the rows are not all the same height - so the
     * test has to stand in for the browser here.
     */
    function overCell(wrapper, day) {
        const cell = wrapper.find(`[data-day="${day}"]`).element;
        document.elementFromPoint = () => cell;
    }

    function pointer(target, type, init = {}) {
        target.dispatchEvent(
            new PointerEvent(type, { bubbles: true, ...init }),
        );
    }

    function chip(wrapper) {
        return wrapper.findAll(".cursor-grab")[0];
    }

    it("moves an event to the day it is dropped on, keeping its time", async () => {
        const wrapper = mountMonth({
            events: [event(1, "2026-08-24T09:00", "2026-08-24T10:00")],
        });

        overCell(wrapper, "2026-08-27");

        pointer(chip(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 10,
            clientY: 10,
        });
        pointer(window, "pointermove", { clientX: 200, clientY: 10 });
        pointer(window, "pointerup", { clientX: 200, clientY: 10 });
        await wrapper.vm.$nextTick();

        const moved = wrapper.emitted("move-event");
        expect(moved).toBeTruthy();

        const start = new Date(moved[0][0].startAt);
        expect(start.getDate()).toBe(27);
        // The cell said nothing about hours, so the time is untouched.
        expect(start.getHours()).toBe(9);
    });

    it("says nothing when it is dropped back where it started", async () => {
        const wrapper = mountMonth({
            events: [event(1, "2026-08-24T09:00", "2026-08-24T10:00")],
        });

        overCell(wrapper, "2026-08-24");

        pointer(chip(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 10,
            clientY: 10,
        });
        pointer(window, "pointermove", { clientX: 40, clientY: 10 });
        pointer(window, "pointerup", { clientX: 40, clientY: 10 });
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("move-event")).toBeFalsy();
    });

    it("does not offer to move an event a module owns", () => {
        const wrapper = mountMonth({
            events: [
                event(1, "2026-08-24T09:00", "2026-08-24T10:00", {
                    readOnly: true,
                }),
            ],
        });

        expect(wrapper.findAll(".cursor-grab")).toHaveLength(0);
    });

    it("does not open the event after a drag", async () => {
        const wrapper = mountMonth({
            events: [event(1, "2026-08-24T09:00", "2026-08-24T10:00")],
        });

        overCell(wrapper, "2026-08-27");

        pointer(chip(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 10,
            clientY: 10,
        });
        pointer(window, "pointermove", { clientX: 200, clientY: 10 });
        pointer(window, "pointerup", { clientX: 200, clientY: 10 });
        await chip(wrapper).trigger("click");

        expect(wrapper.emitted("open-event")).toBeFalsy();
    });
});
