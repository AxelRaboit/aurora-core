import { describe, it, expect, vi, afterEach, beforeEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const request = vi.fn();
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

const StudioCalendarApp = (await import("./StudioCalendarApp.vue")).default;

const i18n = createTestI18n();

const PROPS = {
    scope: "all",
    hasScopeChoice: true,
    spaces: [
        {
            id: 1,
            name: "Atelier Dupont - Réseaux",
            customerName: "Atelier Dupont",
            colourSlot: 2,
        },
    ],
    itemsPath: "/suite/studio/calendar/items",
};

const mounted = [];

function render(search) {
    window.history.replaceState(null, "", `/suite/studio/calendar${search}`);
    const wrapper = mount(StudioCalendarApp, {
        props: PROPS,
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    request.mockReset();
    request.mockResolvedValue({ items: [] });
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe("the editorial calendar", () => {
    it("asks for the window of the month on screen", async () => {
        render("");
        await flushPromises();

        const url = request.mock.calls[0][0];
        expect(url).toContain("from=");
        expect(url).toContain("to=");
        expect(url).not.toContain("state=");
    });

    /**
     * What a dashboard tile opens: every card in the state, whatever its
     * month, so a publication missed last month is on the list.
     */
    it("asks for a state across every month when a state is listed", async () => {
        render("?view=list&state=missed");
        await flushPromises();

        const url = request.mock.calls[0][0];
        expect(url).toContain("state=missed");
        expect(url).not.toContain("from=");
    });

    it("gathers undated cards at the end of the list", async () => {
        request.mockResolvedValue({
            items: [
                {
                    id: 1,
                    title: "Sans date",
                    spaceId: 1,
                    startAt: null,
                    states: ["changes_requested"],
                    stepName: "Idées",
                    path: "/workspace/1?item=1",
                },
                {
                    id: 2,
                    title: "Datée",
                    spaceId: 1,
                    startAt: "2026-08-02T10:00:00+02:00",
                    states: ["changes_requested"],
                    stepName: "À valider",
                    path: "/workspace/1?item=2",
                },
            ],
        });
        const wrapper = render("?view=list&state=changes_requested");
        await flushPromises();

        // Hors du mode d'emploi, dont les étapes sont aussi des `<li>`.
        const titles = wrapper
            .findAll("li")
            .filter((row) => !row.element.closest("[data-guide]"))
            .map((row) => row.text());
        expect(titles[0]).toContain("Datée");
        expect(titles.at(-1)).toContain("Sans date");
    });
});
