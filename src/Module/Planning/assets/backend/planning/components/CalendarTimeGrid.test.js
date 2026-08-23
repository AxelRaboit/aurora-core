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

describe("dragging an event", () => {
    function event(id, startAt, endAt, extra = {}) {
        return {
            id,
            title: `Événement ${id}`,
            startAt: new Date(startAt).toISOString(),
            endAt: new Date(endAt).toISOString(),
            allDay: false,
            colourSlot: 1,
            readOnly: false,
            ...extra,
        };
    }

    /**
     * jsdom lays nothing out, so the columns box is given a rect.
     *
     * 1440 pixels tall over 24 hours is one pixel a minute, and 700 across seven
     * days is 100 a column - which keeps the arithmetic in the assertions
     * readable rather than hidden behind a conversion.
     */
    function mountDraggable(props = {}) {
        const wrapper = mountGrid({
            events: [event(1, "2026-08-17T14:00", "2026-08-17T15:00")],
            ...props,
        });

        // The hour columns' wrapper is the last `.grid.flex-1` - the first is the
        // day header, the second the reminder strip when there is one.
        const boxes = wrapper.findAll(".grid.flex-1");
        const box = boxes.at(-1)?.element;
        if (box) {
            box.getBoundingClientRect = () => ({
                top: 0,
                left: 0,
                width: 700,
                height: 1440,
                right: 700,
                bottom: 1440,
            });
        }

        return wrapper;
    }

    function block(wrapper) {
        return wrapper.findAll(".cursor-grab")[0];
    }

    /**
     * A real `PointerEvent`, not `trigger`.
     *
     * `trigger("pointerdown", { button: 0 })` throws: vue-test-utils assigns the
     * options onto the event, and `button` is read-only on a MouseEvent. The
     * constructor takes it.
     */
    function pointer(target, type, init = {}) {
        target.dispatchEvent(
            new PointerEvent(type, { bubbles: true, ...init }),
        );
    }

    it("offers a grab cursor on an event it can move", () => {
        expect(block(mountDraggable())).toBeTruthy();
    });

    /**
     * An event a module owns is not ours to move, so it is not grabbable.
     *
     * The manager refuses it too - this is the half that stops the reader trying.
     */
    it("does not offer to move an event a module owns", () => {
        const wrapper = mountDraggable({
            events: [
                event(1, "2026-08-17T14:00", "2026-08-17T15:00", {
                    readOnly: true,
                }),
            ],
        });

        expect(wrapper.findAll(".cursor-grab")).toHaveLength(0);
    });

    it("moves both ends by the distance pulled", async () => {
        const wrapper = mountDraggable();

        pointer(block(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 50,
            clientY: 100,
        });
        pointer(window, "pointermove", { clientX: 50, clientY: 160 });
        pointer(window, "pointerup", {});
        await wrapper.vm.$nextTick();

        const moved = wrapper.emitted("move-event");
        expect(moved).toBeTruthy();
        expect(new Date(moved[0][0].startAt).getHours()).toBe(15);
        expect(new Date(moved[0][0].endAt).getHours()).toBe(16);
    });

    it("says nothing when the pointer barely moved", async () => {
        // Every click on an event is a zero-pixel drag. Without the threshold the
        // grid would post an update on each one.
        const wrapper = mountDraggable();

        pointer(block(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 50,
            clientY: 100,
        });
        pointer(window, "pointermove", { clientX: 51, clientY: 101 });
        pointer(window, "pointerup", {});
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("move-event")).toBeFalsy();
    });

    it("ignores a button that is not the left one", async () => {
        const wrapper = mountDraggable();

        pointer(block(wrapper).element, "pointerdown", {
            button: 2,
            clientX: 50,
            clientY: 100,
        });
        pointer(window, "pointermove", { clientX: 50, clientY: 200 });
        pointer(window, "pointerup", {});
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted("move-event")).toBeFalsy();
    });

    /**
     * A drag must not also open the event.
     *
     * The browser fires `click` after `pointerup` on the same element, so without
     * the guard every drag opened the modal on top of the move.
     */
    it("does not open the event after a drag", async () => {
        const wrapper = mountDraggable();

        pointer(block(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 50,
            clientY: 100,
        });
        pointer(window, "pointermove", { clientX: 50, clientY: 160 });
        pointer(window, "pointerup", {});
        await block(wrapper).trigger("click");

        expect(wrapper.emitted("open-event")).toBeFalsy();
    });

    it("still opens the event on a plain click", async () => {
        const wrapper = mountDraggable();

        pointer(block(wrapper).element, "pointerdown", {
            button: 0,
            clientX: 50,
            clientY: 100,
        });
        pointer(window, "pointerup", {});
        await block(wrapper).trigger("click");

        expect(wrapper.emitted("open-event")).toBeTruthy();
    });
});
