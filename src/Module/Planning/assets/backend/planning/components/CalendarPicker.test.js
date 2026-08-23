import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import CalendarPicker from "./CalendarPicker.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

const CALENDARS = [
    { id: 1, name: "Pro", colourSlot: 2 },
    { id: 2, name: "Perso", colourSlot: 3 },
    { id: 3, name: "Équipe", colourSlot: 5 },
    { id: 4, name: "Astreinte", colourSlot: 7 },
    { id: 5, name: "Conférences", colourSlot: 4 },
    { id: 6, name: "Formations", colourSlot: 6 },
];

function mountPicker(props = {}) {
    return mount(CalendarPicker, {
        attachTo: document.body,
        props: {
            calendars: CALENDARS,
            hidden: new Set(),
            countsByCalendar: {},
            ...props,
        },
    });
}

describe("CalendarPicker", () => {
    /**
     * The whole reason it exists: the pill row it replaced scrolled sideways and
     * clipped names once there were more than four calendars.
     */
    it("draws nothing until it is asked, whatever the number of calendars", () => {
        const wrapper = mountPicker();

        expect(wrapper.text()).not.toContain("Formations");

        // And no horizontal scroll anywhere, which is what went wrong before.
        expect(wrapper.html()).not.toContain("overflow-x-auto");
    });

    it("lists every calendar in full once opened", async () => {
        const wrapper = mountPicker();
        await wrapper.find("button").trigger("click");

        for (const calendar of CALENDARS) {
            expect(wrapper.text()).toContain(calendar.name);
        }
    });

    /**
     * Both numbers, because "3" alone cannot say whether anything is folded away
     * - which is the one question the closed trigger has to answer.
     */
    it("says how many are showing out of how many exist", () => {
        expect(mountPicker().text()).toContain("6/6");
        expect(mountPicker({ hidden: new Set([1, 2]) }).text()).toContain(
            "4/6",
        );
    });

    /** Four dots at most: past that they stop being individually readable. */
    it("shows a dot per visible calendar, capped", () => {
        expect(mountPicker().findAll("span.rounded-full")).toHaveLength(4);
        expect(
            mountPicker({ hidden: new Set([1, 2, 3, 4, 5]) }).findAll(
                "span.rounded-full",
            ),
        ).toHaveLength(1);
    });

    it("closes on Escape", async () => {
        const wrapper = mountPicker();
        await wrapper.find("button").trigger("click");
        expect(wrapper.text()).toContain("Formations");

        document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).not.toContain("Formations");
    });

    it("closes on a pointer down outside it, and not inside", async () => {
        const wrapper = mountPicker();
        await wrapper.find("button").trigger("click");

        wrapper.element.dispatchEvent(
            new PointerEvent("pointerdown", { bubbles: true }),
        );
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).toContain("Formations");

        document.body.dispatchEvent(
            new PointerEvent("pointerdown", { bubbles: true }),
        );
        await wrapper.vm.$nextTick();
        expect(wrapper.text()).not.toContain("Formations");
    });

    /**
     * Editing opens a modal on top of this, so the panel has to get out of the
     * way first - otherwise it stays open behind the modal and eats the next
     * click.
     */
    it("stands aside when a calendar is opened for editing", async () => {
        const wrapper = mountPicker({ canManageCalendars: true });
        await wrapper.find("button").trigger("click");

        await wrapper
            .findAll('button[title="backend.plannings.edit_calendar"]')[0]
            .trigger("click");

        expect(wrapper.emitted("edit-calendar")).toEqual([[CALENDARS[0]]]);
        expect(wrapper.text()).not.toContain("Formations");
    });

    it("offers a way out when there are no calendars at all", async () => {
        const wrapper = mountPicker({
            calendars: [],
            canManageCalendars: true,
        });
        await wrapper.find("button").trigger("click");

        expect(wrapper.text()).toContain("backend.plannings.new_calendar");
    });
});
