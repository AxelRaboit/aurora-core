import { beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";

const request = vi.fn();

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast: { success: vi.fn() } }));
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request }),
}));

import AppCategoriesModal from "./AppCategoriesModal.vue";

/**
 * The window both Studio lists manage their categories with: each write goes
 * to the address the screen gave, and the server's answer is handed back up
 * whole, for the screen to redraw its rows.
 */
const PATHS = {
    createPath: "/x/categories/create",
    updatePathTemplate: "/x/categories/__id__/update",
    deletePathTemplate: "/x/categories/__id__/delete",
    reorderPath: "/x/categories/reorder",
    title: "Catégories",
};

const AUDIT = { id: 1, name: "Audits", color: "#bd4a55" };
const STRATEGY = { id: 2, name: "Stratégies", color: null };

function mountModal(categories = [AUDIT, STRATEGY]) {
    return mount(AppCategoriesModal, {
        props: { show: true, categories, ...PATHS },
        global: {
            stubs: {
                AppModal: {
                    template: '<div><slot /><slot name="footer" /></div>',
                },
                AppColorSwatch: true,
            },
        },
    });
}

describe("AppCategoriesModal", () => {
    beforeEach(() => request.mockReset());

    it("creates a category at the address it was given, and hands the answer up", async () => {
        request.mockResolvedValue({
            success: true,
            categories: [
                AUDIT,
                STRATEGY,
                { id: 3, name: "Bilans", color: "#8b6cff" },
            ],
        });
        const wrapper = mountModal();

        await wrapper.find("form input").setValue("Bilans");
        await wrapper.find("form").trigger("submit");
        await flushPromises();

        expect(request).toHaveBeenCalledWith("/x/categories/create", {
            name: "Bilans",
            color: "#8b6cff",
        });
        expect(wrapper.emitted("changed")[0][0].categories).toHaveLength(3);
    });

    it("sends the whole order when a category moves down", async () => {
        request.mockResolvedValue({
            success: true,
            categories: [STRATEGY, AUDIT],
        });
        const wrapper = mountModal();

        await wrapper
            .find('button[title="shared.categories.move_down"]')
            .trigger("click");
        await flushPromises();

        expect(request).toHaveBeenCalledWith("/x/categories/reorder", {
            ids: [2, 1],
        });
    });

    it("asks before deleting, then deletes", async () => {
        request.mockResolvedValue({ success: true, categories: [STRATEGY] });
        const wrapper = mountModal();

        await wrapper
            .find('button[title="shared.common.delete"]')
            .trigger("click");
        expect(request).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain("shared.categories.delete_confirm");

        const confirm = wrapper
            .findAll("button")
            .find(
                (button) =>
                    button.text().includes("shared.common.delete") &&
                    !button.attributes("title"),
            );
        await confirm.trigger("click");
        await flushPromises();

        expect(request).toHaveBeenCalledWith("/x/categories/1/delete", {});
    });
});
