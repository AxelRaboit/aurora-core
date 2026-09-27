import { describe, it, expect, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import StudioPanel from "./StudioPanel.vue";

const i18n = createTestI18n();

const STATS = {
    scope: "mine",
    hasScopeChoice: true,
    missed: 1,
    lateReview: 2,
    changesRequested: 0,
    withClient: 5,
    upcoming: 3,
    awaitingSignature: 4,
    decks: null,
    calendarPath: "/backend/studio/calendar",
    contractsPath: "/backend/studio/contracts",
    attention: [
        {
            id: 7,
            name: "Atelier Dupont",
            customerName: "Atelier Dupont SARL",
            path: "/workspace/7?state=missed",
            missed: 1,
            lateReview: 0,
            changesRequested: 0,
            withClient: 2,
            nextPublication: null,
        },
    ],
};

const mounted = [];

function render(stats = STATS) {
    const wrapper = mount(StudioPanel, {
        props: { stats },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
});

describe("the Studio panel", () => {
    /** Every figure leads somewhere: the calendar, as a list, on its state. */
    it("links each state tile to the editorial calendar list filtered on it", () => {
        const hrefs = render()
            .findAll("a")
            .map((link) => link.attributes("href"));

        expect(hrefs).toContain(
            "/backend/studio/calendar?scope=mine&view=list&state=missed",
        );
        expect(hrefs).toContain(
            "/backend/studio/calendar?scope=mine&view=list&state=late_review",
        );
        expect(hrefs).toContain("/backend/studio/contracts");
    });

    /** A figure the reader may not open is not drawn at all. */
    it("leaves out a tile the server sent as null", () => {
        expect(render().text()).not.toContain("backend.stats.studio.decks");
    });

    it("lists what waits, space by space, each opening its space", () => {
        const row = render().find('a[href="/workspace/7?state=missed"]');

        expect(row.exists()).toBe(true);
        expect(row.text()).toContain("Atelier Dupont");
    });

    it("offers the scope only to a reader for whom mine and all differ", () => {
        expect(render().find('[role="group"]').exists()).toBe(true);
        expect(
            render({ ...STATS, hasScopeChoice: false })
                .find('[role="group"]')
                .exists(),
        ).toBe(false);
    });
});
