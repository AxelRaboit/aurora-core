import { describe, it, expect } from "vitest";
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

describe("DeliverablesApp", () => {
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
