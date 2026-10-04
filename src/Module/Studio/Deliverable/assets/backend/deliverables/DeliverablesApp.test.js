import { afterEach, describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverablesApp from "./DeliverablesApp.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";

const i18n = createTestI18n();

/**
 * La page Livrables de Studio : deux rayons, et sur chaque carte les seuls
 * gestes que la personne y a.
 *
 * Ce qui se casserait sans bruit : un geste proposé à qui le serveur le
 * refusera (changer le rayon d'un livrable dont on n'est pas l'auteur, envoyer
 * un lien sans le droit), ou le mauvais rayon affiché au retour d'un livrable.
 */
const PATHS = {
    createPath: "/backend/studio/deliverables/create",
    scopePathTemplate: "/backend/studio/deliverables/__id__/scope",
    duplicatePathTemplate: "/backend/studio/deliverables/__id__/duplicate",
    deletePathTemplate: "/backend/studio/deliverables/__id__/delete",
    linksPathTemplate: "/backend/studio/deliverables/__id__/links",
};

function row(id, title, extra = {}) {
    return {
        id,
        title,
        summary: "",
        scope: "personal",
        ownerName: "Axel",
        updatedAt: "2026-10-03T10:00:00+00:00",
        editPath: `/backend/studio/deliverables/${id}`,
        previewPath: `/backend/studio/deliverables/${id}/preview`,
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
                DeliverableCategoriesModal: true,
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

/** Trois livrables perso : un audit, une stratégie, un sans catégorie. */
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
        // La dernière section est celle des livrables sans catégorie.
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
                .flatMap((c) => c.props("actions").map((a) => a.key));

        expect(keys(mountFiled())).not.toContain("categories");
        expect(keys(mountFiled({ canManageCategories: true }))).toContain(
            "categories",
        );
    });

    it("offers to copy into a client space only when there is one to write in", () => {
        expect(actionKeys(mountApp())).not.toContain("copy-to-space");

        const wrapper = mountApp({
            copyToSpacePathTemplate:
                "/backend/studio/deliverables/__id__/copy-to-space",
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

    it("hides the create button without the right", () => {
        const wrapper = mountApp({ canCreate: false });

        expect(
            wrapper
                .findAll("button")
                .some((button) => button.text().includes("Nouveau livrable")),
        ).toBe(false);
    });
});
