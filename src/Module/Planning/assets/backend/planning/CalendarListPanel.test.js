import { describe, it, expect, vi, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest, tellPanels } from "@/shared/nav/modulePanelBridge.js";

const CalendarListPanel = (await import("./CalendarListPanel.vue")).default;

const i18n = createTestI18n();

const STATE = {
    calendars: [
        { id: 1, name: "Perso", color: "#4ade80" },
        { id: 2, name: "Équipe", color: "#60a5fa" },
    ],
    hidden: [2],
    countsByCalendar: { 1: 3, 2: 0 },
    canCreateEvents: true,
    canManageCalendars: true,
    zone: "Europe/Paris",
    timezones: ["Europe/Paris", "UTC"],
};

const mounted = [];
const stops = [];

function render() {
    const wrapper = mount(CalendarListPanel, { global: { plugins: [i18n] } });
    mounted.push(wrapper);

    return wrapper;
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    while (stops.length) stops.pop()();
});

describe("the calendars panel", () => {
    /**
     * No fetch of its own, unlike the other two panels: the counts depend on
     * the range the grid is showing, so only the grid can say them. The page is
     * always mounted while this panel is on screen - Planning has one
     * destination - so an announcement is enough.
     */
    it("draws nothing until the page has said something", () => {
        expect(render().text()).toContain("shared.common.loading");
    });

    it("draws the calendars the page announced", async () => {
        const wrapper = render();

        tellPanels("planning:changed", STATE);
        await flushPromises();

        expect(wrapper.text()).toContain("Perso");
        expect(wrapper.text()).toContain("Équipe");
    });

    /** A panel mounted after the grid missed the first announcement. */
    it("asks for the state when it arrives late", () => {
        const asked = vi.fn();
        stops.push(onPanelRequest("planning:announce", asked));

        render();

        expect(asked).toHaveBeenCalled();
    });

    /**
     * The list is `CalendarSidebar`, the component the phone sheet used, and
     * its events go to the page untouched - the handlers the deleted bar
     * already called.
     */
    it("hands the page what the reader did", async () => {
        const toggled = vi.fn();
        stops.push(onPanelRequest("planning:toggle-calendar", toggled));

        const wrapper = render();
        tellPanels("planning:changed", STATE);
        await flushPromises();

        const toggle = wrapper
            .findAll("button")
            .find((b) => b.text().includes("Perso"));
        expect(toggle, "a calendar row is on screen").toBeTruthy();

        await toggle.trigger("click");
        expect(toggled).toHaveBeenCalled();
    });
});
