import { describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// Lu au chargement du module `usePrivileges` : posé avant l'import du
// composant, comme dans le test voisin, mais avec le droit de modifier
// seulement.
vi.hoisted(() => {
    window.__privileges__ = ["studio.spaces.view", "studio.spaces.edit"];
});

import SpaceResourcesView from "./SpaceResourcesView.vue";

const i18n = createTestI18n();

/**
 * Montrer ou cacher une ressource au client demande le droit de partager
 * l'espace : sans lui, la ligne se range, se modifie et se supprime, mais le
 * geste de visibilité n'est pas offert, ni dans la liste ni dans le
 * formulaire.
 */
describe("SpaceResourcesView without the right to share", () => {
    it("keeps the editing gestures and hides the visibility toggle", async () => {
        const wrapper = mount(SpaceResourcesView, {
            global: { plugins: [i18n], stubs: { teleport: true } },
            props: {
                resources: [
                    {
                        id: 1,
                        kind: "text",
                        label: "Facturation",
                        url: null,
                        body: "Le 5.",
                        email: null,
                        phone: null,
                        visibleToClient: false,
                    },
                ],
                resourceCreatePath: "/c",
                resourceUpdatePath: "/u/__id__",
                resourceVisibilityPath: "/v/__id__",
                resourceDeletePath: "/d/__id__",
                resourceReorderPath: "/r",
            },
        });
        await flushPromises();

        const text = wrapper.text();
        expect(text).not.toContain("space_resources.show");
        expect(
            wrapper.find("button[title='shared.common.delete']").exists(),
        ).toBe(true);
    });
});
