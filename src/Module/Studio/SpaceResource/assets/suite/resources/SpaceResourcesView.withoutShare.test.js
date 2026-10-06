import { describe, it, expect, vi } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// Read when the `usePrivileges` module loads: set before the import of the
// component, as in the neighbouring test, but with the right to edit only.
vi.hoisted(() => {
    window.__privileges__ = ["studio.spaces.view", "studio.spaces.edit"];
});

import SpaceResourcesView from "./SpaceResourcesView.vue";

const i18n = createTestI18n();

/**
 * Showing or hiding a resource from the client requires the right to share
 * the space: without it, the row can be reordered, edited and deleted, but
 * the visibility gesture is not offered, neither in the list nor in the
 * form.
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
