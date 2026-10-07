import { afterEach, describe, it, expect, vi } from "vitest";
import { nextTick } from "vue";
import { flushPromises, mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverablesApp from "./DeliverablesApp.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";

const i18n = createTestI18n();

/**
 * Studio's Deliverables page: two shelves, and on each card only the actions
 * the person has on it.
 *
 * What would break silently: an action offered to someone the server will
 * refuse it to (changing the shelf of a deliverable you did not write,
 * sending a link without the right), or the wrong shelf shown on coming back
 * from a deliverable.
 */
const PATHS = {
    createPath: "/suite/studio/deliverables/create",
    scopePathTemplate: "/suite/studio/deliverables/__id__/scope",
    duplicatePathTemplate: "/suite/studio/deliverables/__id__/duplicate",
    deletePathTemplate: "/suite/studio/deliverables/__id__/delete",
    linksPathTemplate: "/suite/studio/deliverables/__id__/links",
};

function row(id, title, extra = {}) {
    return {
        id,
        title,
        summary: "",
        scope: "personal",
        ownerName: "Axel",
        updatedAt: "2026-10-03T10:00:00+00:00",
        editPath: `/suite/studio/deliverables/${id}`,
        previewPath: `/suite/studio/deliverables/${id}/preview`,
        canEdit: true,
        canShare: true,
        canDelete: true,
        canChangeScope: true,
        canDuplicate: true,
        ...extra,
    };
}

function mountApp(extra = {}) {
    return mount(DeliverablesApp, {
        props: {
            personal: [row(1, "Brouillon de proposition")],
            shared: [
                row(2, "Modèle d'audit", {
                    scope: "shared",
                    canChangeScope: false,
                    canShare: false,
                }),
            ],
            canCreate: true,
            ...PATHS,
            ...extra,
        },
        global: {
            plugins: [i18n],
            stubs: {
                DeliverableLinksModal: true,
                DeliverableCopyToSpaceModal: true,
                AppCategoriesModal: true,
            },
        },
    });
}

function actionKeys(wrapper) {
    return wrapper
        .findComponent(AppRowActions)
        .props("actions")
        .map((action) => action.key);
}

const CATEGORIES = [
    { id: 1, name: "Audit", color: "#bd4a55", position: 1 },
    { id: 2, name: "Stratégie", color: null, position: 2 },
];

/** Three personal deliverables: an audit, a strategy, one without a category. */
function mountFiled(extra = {}) {
    return mountApp({
        personal: [
            row(1, "Audit Dupont", { category: CATEGORIES[0] }),
            row(2, "Stratégie Fabre", { category: CATEGORIES[1] }),
            row(3, "Brouillon libre", { category: null }),
        ],
        categories: CATEGORIES,
        ...extra,
    });
}

function sectionTitles(wrapper) {
    return wrapper.findAll("section h3").map((title) => title.text());
}

describe("DeliverablesApp", () => {
    afterEach(() => window.history.replaceState(null, "", "/"));

    it("shows one section per category, the uncategorised last", () => {
        const wrapper = mountFiled();
        const titles = sectionTitles(wrapper);

        expect(titles).toHaveLength(3);
        expect(titles[0]).toContain("Audit");
        expect(titles[1]).toContain("Stratégie");
        // The last section is the one for deliverables without a category.
        expect(titles[2]).toContain("categories.none");
        expect(wrapper.findAll("section").at(-1).text()).toContain(
            "Brouillon libre",
        );
    });

    it("lists without sections when there is no category, or when asked to", () => {
        expect(sectionTitles(mountApp())).toHaveLength(0);

        window.history.replaceState(null, "", "/?layout=flat");
        const wrapper = mountFiled();
        expect(sectionTitles(wrapper)).toHaveLength(0);
        expect(wrapper.text()).toContain("Audit Dupont");
        expect(wrapper.text()).toContain("Brouillon libre");
    });

    it("filters on the category the address asks for", () => {
        window.history.replaceState(null, "", "/?category=2");
        const wrapper = mountFiled();

        expect(wrapper.text()).toContain("Stratégie Fabre");
        expect(wrapper.text()).not.toContain("Audit Dupont");
        expect(wrapper.text()).not.toContain("Brouillon libre");
    });

    it("falls back to every category when the address names one that is gone", () => {
        window.history.replaceState(null, "", "/?category=99");
        const wrapper = mountFiled();

        expect(wrapper.text()).toContain("Audit Dupont");
        expect(wrapper.text()).toContain("Brouillon libre");
    });

    it("filters on what has no category yet", () => {
        window.history.replaceState(null, "", "/?category=none");
        const wrapper = mountFiled();

        expect(wrapper.text()).toContain("Brouillon libre");
        expect(wrapper.text()).not.toContain("Audit Dupont");
    });

    it("offers to manage categories only with the right", () => {
        const keys = (wrapper) =>
            wrapper
                .findAllComponents({ name: "AppPageActions" })
                .flatMap((component) =>
                    component.props("actions").map((action) => action.key),
                );

        expect(keys(mountFiled())).not.toContain("categories");
        expect(keys(mountFiled({ canManageCategories: true }))).toContain(
            "categories",
        );
    });

    it("offers to copy into a client space only when there is one to write in", () => {
        expect(actionKeys(mountApp())).not.toContain("copy-to-space");

        const wrapper = mountApp({
            copyToSpacePathTemplate:
                "/suite/studio/deliverables/__id__/copy-to-space",
            copyTargets: [
                {
                    id: 7,
                    name: "Atelier Dupont",
                    customer: "Atelier Dupont SARL",
                },
            ],
        });
        expect(actionKeys(wrapper)).toContain("copy-to-space");
    });

    it("opens on my deliverables by default", () => {
        const wrapper = mountApp();

        expect(wrapper.text()).toContain("Brouillon de proposition");
        expect(wrapper.text()).not.toContain("Modèle d'audit");
    });

    it("opens on the scope the address asks for", () => {
        const wrapper = mountApp({ initialScope: "shared" });

        expect(wrapper.text()).toContain("Modèle d'audit");
        expect(wrapper.text()).not.toContain("Brouillon de proposition");
    });

    it("offers the author to share a personal deliverable", () => {
        expect(actionKeys(mountApp())).toEqual(
            expect.arrayContaining([
                "edit",
                "preview",
                "links",
                "scope",
                "duplicate",
                "delete",
            ]),
        );
    });

    it("leaves out what the server would refuse", () => {
        const keys = actionKeys(mountApp({ initialScope: "shared" }));

        expect(keys).not.toContain("scope");
        expect(keys).not.toContain("links");
    });

    it("marks a template, names the client, and filters on templates from the address", async () => {
        const personal = [
            row(1, "Audit type", { template: true }),
            row(2, "Proposition à Fabre", {
                customer: { id: 4, legalName: "Menuiserie Fabre" },
            }),
        ];

        const all = mountApp({ personal });
        expect(all.text()).toContain("template.badge");
        expect(all.text()).toContain("deliverables.for_customer");
        expect(all.text()).toContain("Proposition à Fabre");

        window.history.replaceState(null, "", "/?templates=1");
        const templates = mountApp({ personal });
        expect(templates.text()).toContain("Audit type");
        expect(templates.text()).not.toContain("Proposition à Fabre");

        // The pressed pill is released: everything comes back, and so does the address.
        const pill = templates
            .findAll("[aria-pressed]")
            .find((tab) => tab.text().includes("template.filter"));
        await pill.trigger("click");
        await flushPromises();
        expect(window.location.search).toBe("");
        expect(templates.text()).toContain("Proposition à Fabre");
    });

    it("starts a new deliverable from a template, in its category", async () => {
        const send = vi.fn().mockResolvedValue({ success: false, errors: {} });
        vi.doMock("./composables/useDeliverableRequest.js", () => ({
            useDeliverableRequest: () => ({ send }),
        }));
        vi.resetModules();
        const { default: App } = await import("./DeliverablesApp.vue");

        const wrapper = mount(App, {
            props: {
                personal: [row(1, "Brouillon")],
                shared: [
                    row(2, "Audit type", {
                        scope: "shared",
                        template: true,
                        category: CATEGORIES[0],
                    }),
                ],
                categories: CATEGORIES,
                canCreate: true,
                ...PATHS,
            },
            global: {
                plugins: [i18n],
                stubs: {
                    DeliverableLinksModal: true,
                    DeliverableCopyToSpaceModal: true,
                    AppCategoriesModal: true,
                    AppModal: {
                        template: "<div><slot /><slot name='footer' /></div>",
                    },
                },
            },
        });

        const templateSelect = wrapper
            .findAllComponents({ name: "AppSelect" })
            .find(
                (select) =>
                    "suite.studio.deliverables.template.from" ===
                    select.props("label"),
            );
        expect(templateSelect.props("options")).toEqual([
            { value: 2, label: "Audit type", categoryId: 1 },
        ]);

        templateSelect.vm.$emit("update:modelValue", 2);
        await nextTick();
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(send).toHaveBeenCalledWith(
            PATHS.createPath,
            expect.objectContaining({ fromTemplateId: 2, categoryId: 1 }),
        );
        vi.doUnmock("./composables/useDeliverableRequest.js");
    });

    it("creates a presentation, offering the presentation templates only", async () => {
        const send = vi.fn().mockResolvedValue({ success: false, errors: {} });
        vi.doMock("./composables/useDeliverableRequest.js", () => ({
            useDeliverableRequest: () => ({ send }),
        }));
        vi.resetModules();
        const { default: App } = await import("./DeliverablesApp.vue");

        const wrapper = mount(App, {
            props: {
                personal: [
                    row(1, "Audit type", { template: true, format: "page" }),
                    row(2, "Lancement type", {
                        template: true,
                        format: "slides",
                    }),
                ],
                shared: [],
                canCreate: true,
                ...PATHS,
            },
            global: {
                plugins: [i18n],
                stubs: {
                    DeliverableLinksModal: true,
                    DeliverableCopyToSpaceModal: true,
                    AppCategoriesModal: true,
                    AppModal: {
                        template: "<div><slot /><slot name='footer' /></div>",
                    },
                },
            },
        });

        const templateOptions = () =>
            wrapper
                .findAllComponents({ name: "AppSelect" })
                .find(
                    (select) =>
                        "suite.studio.deliverables.template.from" ===
                        select.props("label"),
                )
                .props("options")
                .map((option) => option.label);
        expect(templateOptions()).toEqual(["Audit type"]);

        const format = wrapper.findComponent({ name: "AppChoiceRow" });
        expect(format.props("options").map((option) => option.value)).toEqual([
            "page",
            "slides",
        ]);
        format.vm.$emit("update:modelValue", "slides");
        await nextTick();
        expect(templateOptions()).toEqual(["Lancement type"]);

        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(send).toHaveBeenCalledWith(
            PATHS.createPath,
            expect.objectContaining({ format: "slides", fromTemplateId: null }),
        );
        vi.doUnmock("./composables/useDeliverableRequest.js");
    });

    it("offers to copy a presentation into a client space, and badges it", () => {
        const wrapper = mountApp({
            personal: [row(1, "Lancement", { format: "slides" })],
            copyToSpacePathTemplate:
                "/suite/studio/deliverables/__id__/copy-to-space",
            copyTargets: [
                {
                    id: 7,
                    name: "Atelier Dupont",
                    customer: "Atelier Dupont SARL",
                },
            ],
        });

        // A presentation lives in a space too: its slides travel with it.
        expect(actionKeys(wrapper)).toContain("copy-to-space");
        expect(wrapper.text()).toContain("format.badge_slides");
    });

    it("filters on the format the address asks for, and counts each", async () => {
        const personal = [
            row(1, "Bilan trimestriel", { format: "page" }),
            row(2, "Réunion de lancement", { format: "slides" }),
            row(3, "Ancienne note"),
        ];

        window.history.replaceState(null, "", "/?format=slides");
        const wrapper = mountApp({ personal });
        expect(wrapper.text()).toContain("Réunion de lancement");
        expect(wrapper.text()).not.toContain("Bilan trimestriel");

        const tabs = wrapper
            .findAll("[aria-pressed]")
            .filter((tab) => tab.text().includes("format.filter_"));
        expect(
            tabs.map((tab) => tab.text().replace(/\s+/g, " ").trim()),
        ).toEqual([
            expect.stringContaining("3"),
            expect.stringContaining("2"),
            expect.stringContaining("1"),
        ]);

        // With no format written, a row is a page.
        await tabs[1].trigger("click");
        await flushPromises();
        expect(window.location.search).toBe("?format=page");
        expect(wrapper.text()).toContain("Bilan trimestriel");
        expect(wrapper.text()).toContain("Ancienne note");
        expect(wrapper.text()).not.toContain("Réunion de lancement");

        await tabs[0].trigger("click");
        await flushPromises();
        expect(window.location.search).toBe("");
        expect(wrapper.text()).toContain("Réunion de lancement");
    });

    it("falls back to every format when the address names an unknown one", () => {
        window.history.replaceState(null, "", "/?format=video");
        const wrapper = mountApp({
            personal: [
                row(1, "Bilan", { format: "page" }),
                row(2, "Lancement", { format: "slides" }),
            ],
        });

        expect(wrapper.text()).toContain("Bilan");
        expect(wrapper.text()).toContain("Lancement");
    });

    it("imports a text into a presentation, and says why when nothing comes of it", async () => {
        const send = vi.fn().mockResolvedValue({
            success: false,
            errors: { blocks: "suite.studio.deliverables.errors.import_empty" },
        });
        vi.doMock("./composables/useDeliverableRequest.js", () => ({
            useDeliverableRequest: () => ({ send }),
        }));
        vi.resetModules();
        const { default: App } = await import("./DeliverablesApp.vue");

        const wrapper = mount(App, {
            props: {
                personal: [],
                shared: [],
                canCreate: true,
                ...PATHS,
                importPath: "/suite/studio/deliverables/import",
            },
            global: {
                plugins: [i18n],
                stubs: {
                    DeliverableLinksModal: true,
                    DeliverableCopyToSpaceModal: true,
                    AppCategoriesModal: true,
                    AppBlockEditor: {
                        name: "AppBlockEditor",
                        props: ["modelValue", "placeholder"],
                        emits: ["update:modelValue"],
                        template: "<div />",
                    },
                    AppModal: {
                        template: "<div><slot /><slot name='footer' /></div>",
                    },
                },
            },
        });

        const actions = wrapper
            .findComponent({ name: "AppPageActions" })
            .props("actions");
        expect(actions.map((action) => action.key)).toEqual([
            "create",
            "import",
        ]);
        actions.find((action) => "import" === action.key).onSelect();
        await nextTick();

        const blocks = [{ type: "header", data: { text: "Ordre du jour" } }];
        wrapper
            .findComponent({ name: "AppBlockEditor" })
            .vm.$emit("update:modelValue", blocks);
        await wrapper.findAll("form")[1].trigger("submit");
        await flushPromises();

        expect(send).toHaveBeenCalledWith(
            "/suite/studio/deliverables/import",
            expect.objectContaining({
                blocks,
                scope: "personal",
                categoryId: null,
            }),
        );
        expect(wrapper.text()).toContain(
            "suite.studio.deliverables.errors.import_empty",
        );
        vi.doUnmock("./composables/useDeliverableRequest.js");
    });

    it("offers no import without the route or the right to create", () => {
        const withoutRoute = mountApp();
        expect(
            withoutRoute
                .findComponent({ name: "AppPageActions" })
                .props("actions")
                .map((action) => action.key),
        ).not.toContain("import");

        const withoutRight = mountApp({
            canCreate: false,
            importPath: "/suite/studio/deliverables/import",
        });
        expect(
            withoutRight.findComponent({ name: "AppPageActions" }).exists(),
        ).toBe(false);
    });

    it("hides the create button without the right", () => {
        const wrapper = mountApp({ canCreate: false });

        expect(
            wrapper
                .findAll("button")
                .some((button) => button.text().includes("Nouveau livrable")),
        ).toBe(false);
    });
});
