import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import SpaceDeliverablesView from "./SpaceDeliverablesView.vue";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import DeliverableLinksModal from "./components/DeliverableLinksModal.vue";

const i18n = createTestI18n();

/**
 * Les livrables d'un espace, vus du studio.
 *
 * Ce qui se casserait sans bruit : les liens de lecture depuis la liste. Ils
 * n'étaient proposés que dans l'éditeur, et créer une adresse pour un
 * destinataire obligeait à ouvrir le document d'abord. La fenêtre doit
 * s'ouvrir sur les liens **du livrable choisi**, pas sur ceux d'un voisin.
 */
const PATHS = {
    createPath: "/workspace/1/deliverables/create",
    visibilityPathTemplate: "/workspace/1/deliverables/__id__/visibility",
    duplicatePathTemplate: "/workspace/1/deliverables/__id__/duplicate",
    deletePathTemplate: "/workspace/1/deliverables/__id__/delete",
};

const AUDIT = {
    id: 7,
    title: "Audit de présence en ligne",
    summary: "",
    visibleToClient: true,
    updatedAt: "2026-10-02T10:00:00+00:00",
    editPath: "/workspace/1/deliverables/7/edit",
    previewPath: "/workspace/1/deliverables/7/preview",
};

function mountView(extra = {}) {
    return mount(SpaceDeliverablesView, {
        props: { deliverables: [AUDIT], canEdit: true, ...PATHS, ...extra },
        global: { plugins: [i18n], stubs: { DeliverableLinksModal: true } },
    });
}

function actionKeys(wrapper) {
    return wrapper
        .findComponent(AppRowActions)
        .props("actions")
        .map((action) => action.key);
}

describe("SpaceDeliverablesView", () => {
    it("offers the reading links in a deliverable's menu", () => {
        const wrapper = mountView({
            linksPathTemplate: "/workspace/1/deliverables/__id__/links",
        });

        expect(actionKeys(wrapper)).toContain("links");
    });

    it("opens the links of the chosen deliverable", async () => {
        const wrapper = mountView({
            linksPathTemplate: "/workspace/1/deliverables/__id__/links",
        });

        wrapper
            .findComponent(AppRowActions)
            .props("actions")
            .find((action) => "links" === action.key)
            .onSelect();
        await wrapper.vm.$nextTick();

        const modal = wrapper.findComponent(DeliverableLinksModal);
        expect(modal.props("show")).toBe(true);
        expect(modal.props("linksPath")).toBe(
            "/workspace/1/deliverables/7/links",
        );
    });

    it("leaves the action out without a links path", () => {
        expect(actionKeys(mountView())).not.toContain("links");
    });
});
