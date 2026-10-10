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
    suite: {
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
                route: "suite_ged_documents",
                path: "/suite/ged/documents",
                labelKey: "suite.nav.documents",
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
                    route: "suite_ged_documents",
                    path: "/suite/ged/documents",
                    labelKey: "suite.nav.documents",
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
            activeRoute: "suite_ged_documents",
            moduleNavView,
        },
        global: { plugins: [i18n] },
    });
}

const buttonSaying = (wrapper, text) =>
    wrapper.findAll("button").find((button) => button.text().includes(text));

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

describe("the site's logo", () => {
    function renderWithLogo(siteLogoUrl) {
        return mount(AppSidemenu, {
            props: {
                navSections: NAV_SECTIONS,
                activeRoute: "suite_ged_documents",
                moduleNavView: GED_VIEW,
                siteLogoUrl,
            },
            global: { plugins: [i18n] },
        });
    }

    /**
     * The wide sidebar read the logo setting, and the phone bar and its drawer
     * kept drawing the default mark: a logo set in Branding only showed on a
     * large screen.
     */
    it("shows in the wide sidebar, the phone bar and the phone drawer", () => {
        const wrapper = renderWithLogo("/uploads/ged/logo.png");

        expect(
            wrapper.findAll('img[src="/uploads/ged/logo.png"]'),
        ).toHaveLength(3);
        expect(wrapper.findAll("svg[viewBox='0 0 64 64']")).toHaveLength(0);
    });

    it("falls back to the default mark everywhere when none is set", () => {
        const wrapper = renderWithLogo("");

        expect(wrapper.findAll("img[alt='Logo']")).toHaveLength(0);
        expect(wrapper.findAll("svg[viewBox='0 0 64 64']")).toHaveLength(3);
    });
});

describe("the figure beside an entry", () => {
    function renderWith(item) {
        return mount(AppSidemenu, {
            props: {
                navSections: [
                    {
                        id: "ged",
                        items: [{ ...NAV_SECTIONS[0].items[0], ...item }],
                    },
                ],
                activeRoute: "suite_ged_documents",
            },
            global: { plugins: [i18n] },
        });
    }

    it("prints the count the server wrote, zero included", () => {
        const figures = renderWith({ count: 0 }).findAll("[data-nav-count]");

        expect(figures.length).toBeGreaterThan(0);
        expect(figures[0].text()).toBe("0");
    });

    it("prints a large count in the reader's language", () => {
        const figure = renderWith({ count: 1204 }).find("[data-nav-count]");

        // French groups thousands with a narrow no-break space.
        expect(figure.text().replace(/\s/gu, " ")).toBe("1 204");
    });

    it("prints nothing for an entry nobody counts", () => {
        expect(renderWith({}).find("[data-nav-count]").exists()).toBe(false);
    });

    it("draws no icon on a row", () => {
        // `.si`: the menu's rows. The logo links to the same first page and
        // keeps its mark.
        const rows = renderWith({ count: 3 }).findAll(
            "a.si[href='/suite/ged/documents']",
        );

        expect(rows.length).toBeGreaterThan(0);
        rows.forEach((row) => expect(row.find("svg").exists()).toBe(false));
    });
});

/**
 * The sidemenu audit of 10/10/2026: one head for the column and the phone
 * drawer, a filter that offers the other modules, and the attributes a
 * keyboard or a screen reader needs.
 */
describe("the column's head, the drawer's too", () => {
    it("tells the drawer which module it shows, and how to leave it", () => {
        const wrapper = render();
        const drawer = wrapper.find(".sidemenu-drawer");

        expect(drawer.find("[data-sidemenu-module-path]").exists()).toBe(true);
        expect(drawer.find("[data-sidemenu-back-to-modules]").exists()).toBe(
            true,
        );
        expect(drawer.find("[data-sidemenu-filter]").exists()).toBe(true);
    });

    it("offers every module when the filter finds nothing in this one", async () => {
        const wrapper = render();

        await wrapper
            .find("#sidemenu [data-sidemenu-filter]")
            .setValue("nothing-like-this");
        const searchAll = wrapper.find("#sidemenu [data-sidemenu-search-all]");
        expect(searchAll.exists()).toBe(true);

        await searchAll.trigger("click");
        expect(buttonSaying(wrapper, "Revenir à GED")).toBeTruthy();
        expect(
            wrapper.find("#sidemenu [data-sidemenu-filter]").element.value,
        ).toBe("nothing-like-this");
    });

    it("clears the filter on Escape before anything else", async () => {
        const wrapper = render();
        const filter = wrapper.find("#sidemenu [data-sidemenu-filter]");

        await filter.setValue("doc");
        await filter.trigger("keydown", { key: "Escape" });

        expect(filter.element.value).toBe("");
        // Still in the module: clearing a search does not leave it.
        expect(buttonSaying(wrapper, "Tous les modules")).toBeTruthy();
    });

    it("signs the foot with the version", () => {
        const wrapper = mount(AppSidemenu, {
            props: {
                navSections: NAV_SECTIONS,
                activeRoute: "suite_ged_documents",
                appVersion: "4.9.0",
            },
            global: { plugins: [i18n] },
        });

        expect(wrapper.find("[data-sidemenu-foot]").text()).toContain("4.9.0");
    });
});

describe("what a screen reader is told", () => {
    it("marks the open page as the current one", () => {
        const current = render(null).findAll(
            "#sidemenu a[aria-current='page']",
        );

        expect(current.length).toBe(1);
        expect(current[0].attributes("href")).toBe("/suite/ged/documents");
    });
});

describe("a section with a single entry", () => {
    it("draws the entry alone, without a header that repeats it", () => {
        const wrapper = render(null);

        expect(wrapper.find("#sidemenu .si-section-header").exists()).toBe(
            false,
        );
        // Filtered by hand: the selector engine under jsdom misses an `href`
        // attribute selector once it is scoped by an id.
        const rows = wrapper
            .findAll("#sidemenu nav a")
            .filter(
                (link) => "/suite/ged/documents" === link.attributes("href"),
            );
        expect(rows).toHaveLength(1);
        // The section's dot moves onto the entry.
        expect(rows[0].find("span.rounded-full").exists()).toBe(true);
    });

    it("folds with the rest, its header back in place of the entry", async () => {
        const wrapper = render(null);

        await wrapper
            .find("#sidemenu [data-sidemenu-fold-all]")
            .trigger("click");

        const header = wrapper.find("#sidemenu .si-section-header");
        expect(header.exists()).toBe(true);
        expect(header.attributes("aria-expanded")).toBe("false");
        expect(
            wrapper
                .findAll("#sidemenu nav a")
                .filter(
                    (link) =>
                        "/suite/ged/documents" === link.attributes("href"),
                ),
        ).toHaveLength(0);

        await header.trigger("click");

        expect(wrapper.find("#sidemenu .si-section-header").exists()).toBe(
            false,
        );
        expect(
            wrapper
                .findAll("#sidemenu nav a")
                .filter(
                    (link) =>
                        "/suite/ged/documents" === link.attributes("href"),
                ),
        ).toHaveLength(1);
    });

    it("keeps a foldable header, with its state, over two entries", () => {
        const wrapper = mount(AppSidemenu, {
            props: {
                navSections: [
                    {
                        id: "ged",
                        items: [
                            NAV_SECTIONS[0].items[0],
                            {
                                route: "suite_ged_tags",
                                path: "/suite/ged/tags",
                                labelKey: "suite.nav.ged_tags",
                                icon: "tags",
                                children: [],
                            },
                        ],
                    },
                ],
                activeRoute: "suite_ged_documents",
            },
            global: { plugins: [i18n] },
        });

        expect(
            wrapper
                .find("#sidemenu .si-section-header")
                .attributes("aria-expanded"),
        ).toBe("true");
    });
});
