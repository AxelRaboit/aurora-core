import { beforeEach, describe, expect, it, vi } from "vitest";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

const { useSidemenuNav } = await import("./useSidemenuNav.js");

const PROJECT_SECTIONS = [
    {
        id: "general",
        items: [
            {
                route: "backend_dashboard",
                path: "/backend",
                labelKey: "backend.nav.dashboard",
                icon: "layout-dashboard",
                children: [],
            },
        ],
    },
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
    panelComponent: "ged/backend/documents/FolderTreePanel",
    groups: [
        {
            id: "library",
            labelKey: "backend.nav.ged_groups.library",
            items: [
                {
                    route: "backend_ged_documents",
                    path: "/backend/ged/documents",
                    labelKey: "backend.nav.documents",
                    icon: "folder-open",
                    children: [],
                },
                {
                    route: "backend_ged_trash",
                    path: "/backend/ged/trash",
                    labelKey: "backend.nav.ged_trash",
                    icon: "folder",
                    children: [],
                },
            ],
        },
        {
            id: "loose",
            labelKey: null,
            items: [
                {
                    route: "backend_ged_settings",
                    path: "/backend/ged/settings",
                    labelKey: "backend.nav.settings",
                    icon: "settings",
                    children: [],
                },
            ],
        },
    ],
};

function makeNav(moduleNavView = null, activeRoute = "backend_ged_documents") {
    return useSidemenuNav(
        PROJECT_SECTIONS,
        activeRoute,
        {},
        {},
        {},
        moduleNavView,
    );
}

/**
 * The column has two views and shows one at a time - that is the whole point,
 * and the reason the menu costs the page no extra width.
 */
describe("the two views of the side menu", () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it("stays in the project view when no module declared one", () => {
        const nav = makeNav(null);

        expect(nav.hasModuleView.value).toBe(false);
        expect(nav.inModuleView.value).toBe(false);
        expect(nav.activeSections.value).toBe(nav.groupedSections.value);
    });

    it("opens on the module view the server resolved", () => {
        const nav = makeNav(GED_VIEW);

        expect(nav.inModuleView.value).toBe(true);
        expect(nav.activeSections.value).toBe(nav.moduleSections.value);
    });

    it("goes back to the project view and stays there", () => {
        const nav = makeNav(GED_VIEW);

        nav.backToProject();

        expect(nav.inModuleView.value).toBe(false);
        expect(nav.activeSections.value).toBe(nav.groupedSections.value);
    });

    it("can be sent back into the module view", () => {
        const nav = makeNav(GED_VIEW);

        nav.backToProject();
        nav.enterModuleView();

        expect(nav.inModuleView.value).toBe(true);
    });

    // Nothing to enter when nothing was resolved - otherwise the column would
    // switch to an empty view.
    it("refuses to enter a module view that does not exist", () => {
        const nav = makeNav(null);

        nav.enterModuleView();

        expect(nav.inModuleView.value).toBe(false);
    });
});

describe("the shape of a module view's sections", () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it("keys fold state per group but borrows one colour from the module", () => {
        const [library, loose] = makeNav(GED_VIEW).moduleSections.value;

        expect(library.id).toBe("ged:library");
        expect(loose.id).toBe("ged:loose");
        // Both groups tint lime, because both are the GED.
        expect(library.themeId).toBe("ged");
        expect(loose.themeId).toBe("ged");
    });

    // A group with no header has no control to unfold it with, so it must never
    // be foldable - a folded headerless group would be unreachable.
    it("marks a headerless group as not foldable", () => {
        const [library, loose] = makeNav(GED_VIEW).moduleSections.value;

        expect(library.foldable).toBe(true);
        expect(loose.foldable).toBe(false);
        expect(loose.label).toBe("");
    });

    it("builds rows the same way the project view does", () => {
        const [library] = makeNav(GED_VIEW).moduleSections.value;

        expect(library.items.map((item) => item.route)).toEqual([
            "backend_ged_documents",
            "backend_ged_trash",
        ]);
        // Resolved through the same ICON_MAP, so a row is a row in both views.
        expect(library.items[0].icon).toBeTruthy();
    });

    it("names the module from its section label key", () => {
        expect(makeNav(GED_VIEW).moduleLabel.value).toBe(
            "backend.nav.sections.ged",
        );
    });

    it("lets an admin alias rename the module heading", () => {
        const nav = useSidemenuNav(
            PROJECT_SECTIONS,
            "backend_ged_documents",
            { ged: "  Documents  " },
            {},
            {},
            GED_VIEW,
        );

        expect(nav.moduleLabel.value).toBe("Documents");
    });
});

describe("the nav filter", () => {
    beforeEach(() => {
        localStorage.clear();
    });

    // Decision on record: the filter searches what is on screen, the palette
    // searches everywhere. A field returning rows the column is not showing
    // would need a sentence to explain itself.
    it("searches the module view while the module view is open", () => {
        const nav = makeNav(GED_VIEW);

        nav.navFilter.value = "ged_trash";

        const routes = nav.displayedSections.value.flatMap((s) =>
            s.items.map((i) => i.route),
        );
        expect(routes).toEqual(["backend_ged_trash"]);
    });

    it("searches the project view once the reader has gone back", () => {
        const nav = makeNav(GED_VIEW);

        nav.backToProject();
        nav.navFilter.value = "dashboard";

        const routes = nav.displayedSections.value.flatMap((s) =>
            s.items.map((i) => i.route),
        );
        expect(routes).toEqual(["backend_dashboard"]);
    });

    it("finds nothing from the other view", () => {
        const nav = makeNav(GED_VIEW);

        // `backend_dashboard` is a project row, and the column is in the GED.
        nav.navFilter.value = "dashboard";

        expect(nav.displayedSections.value).toEqual([]);
    });
});

describe("what the search palette is offered", () => {
    beforeEach(() => {
        localStorage.clear();
    });

    // The audit's first cost: a destination declared only at module level was
    // findable nowhere. It is now in the palette's index.
    it("includes the open module's destinations alongside the project's", () => {
        const routes = makeNav(GED_VIEW).navItems.value.map((i) => i.route);

        expect(routes).toContain("backend_dashboard");
        expect(routes).toContain("backend_ged_trash");
    });

    it("offers only project destinations when no module view was resolved", () => {
        const routes = makeNav(null).navItems.value.map((i) => i.route);

        expect(routes).toEqual(["backend_dashboard", "backend_ged_documents"]);
    });
});
