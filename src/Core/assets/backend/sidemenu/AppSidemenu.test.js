import { beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

// jsdom ships no `matchMedia`, and the menu asks it whether the viewport is
// desktop-sized before it draws anything.
window.matchMedia = vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    onchange: null,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
}));

const AppSidemenu = (await import("./AppSidemenu.vue")).default;

const i18n = createTestI18n({
    backend: {
        nav: {
            back_to_modules: "Tous les modules",
            back_to_module: "Revenir à {module}",
            sections: { ged: "GED" },
            documents: "Documents",
        },
    },
});

const NAV_SECTIONS = [
    {
        id: "ged",
        items: [
            {
                route: "backend_ged_documents",
                path: "/backend/ged/documents",
                labelKey: "backend.nav.documents",
                icon: "folder-open",
                children: [],
            },
        ],
    },
];

const GED_VIEW = {
    moduleId: "ged",
    panelComponent: null,
    groups: [
        {
            id: "destinations",
            labelKey: null,
            items: [
                {
                    route: "backend_ged_documents",
                    path: "/backend/ged/documents",
                    labelKey: "backend.nav.documents",
                    icon: "folder-open",
                    children: [],
                },
            ],
        },
    ],
};

function render(moduleNavView = GED_VIEW) {
    return mount(AppSidemenu, {
        props: {
            navSections: NAV_SECTIONS,
            activeRoute: "backend_ged_documents",
            moduleNavView,
        },
        global: { plugins: [i18n] },
    });
}

const buttonSaying = (wrapper, text) =>
    wrapper.findAll("button").find((b) => b.text().includes(text));

beforeEach(() => {
    localStorage.clear();
});

describe("switching between the two menu views", () => {
    it("opens on the module view the server resolved", () => {
        expect(buttonSaying(render(), "Tous les modules")).toBeTruthy();
    });

    /**
     * The door used to swing one way. `enterModuleView` existed in the
     * composable and was tested there from the day the view shipped, but no
     * control called it: one press of the "all modules" row and the module's
     * own menu was gone until the page was reloaded.
     *
     * Covered here rather than on the composable because the composable was
     * never the broken part - the wiring was, and only a mounted component
     * sees a function nothing calls.
     */
    it("offers a way back into the module view after leaving it", async () => {
        const wrapper = render();

        await buttonSaying(wrapper, "Tous les modules").trigger("click");
        const backIn = buttonSaying(wrapper, "Revenir à GED");
        expect(backIn).toBeTruthy();

        await backIn.trigger("click");
        expect(buttonSaying(wrapper, "Tous les modules")).toBeTruthy();
        expect(buttonSaying(wrapper, "Revenir à GED")).toBeFalsy();
    });

    /** No module view for this page, nothing to return to, no control. */
    it("does not offer the way back when the page belongs to no module", () => {
        expect(buttonSaying(render(null), "Revenir à")).toBeFalsy();
    });
});
