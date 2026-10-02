import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import AppPagination from "./AppPagination.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

function mountPagination(props) {
    return mount(AppPagination, {
        props,
        global: {
            stubs: {
                AppButton: {
                    template:
                        '<button :disabled="disabled" @click="$emit(\'click\')"><slot />{{ label }}</button>',
                    props: [
                        "variant",
                        "size",
                        "disabled",
                        "label",
                        "iconOnlyOnPhone",
                    ],
                    emits: ["click"],
                },
            },
        },
    });
}

describe("AppPagination", () => {
    it("renders nothing when totalPages <= 1", () => {
        const wrapper = mountPagination({ page: 1, totalPages: 1 });
        expect(wrapper.find("div").exists()).toBe(false);
    });

    it("renders prev/next buttons when totalPages > 2", () => {
        const wrapper = mountPagination({ page: 2, totalPages: 5 });
        const buttons = wrapper.findAll("button");
        expect(buttons.length).toBeGreaterThanOrEqual(2);
    });

    it("shows page numbers when totalPages <= 10", () => {
        const wrapper = mountPagination({ page: 1, totalPages: 5 });
        // Page numbers are rendered as plain buttons in template
        const pageButtons = wrapper.findAll("button[type='button']");
        expect(pageButtons.length).toBe(5);
    });

    it("emits change event with correct page on button click", async () => {
        const wrapper = mountPagination({ page: 1, totalPages: 5 });
        const pageButtons = wrapper.findAll("button[type='button']");
        await pageButtons[2].trigger("click"); // page 3
        expect(wrapper.emitted("change")).toBeTruthy();
        expect(wrapper.emitted("change")[0]).toEqual([3]);
    });

    it("disables prev on first page", () => {
        const wrapper = mountPagination({ page: 1, totalPages: 5 });
        const prevButton = wrapper
            .findAll("button")
            .find((b) => b.text().includes("pagination.previous"));
        expect(prevButton.attributes("disabled")).toBeDefined();
    });

    it("disables next on last page", () => {
        const wrapper = mountPagination({ page: 5, totalPages: 5 });
        const nextButton = wrapper
            .findAll("button")
            .find((b) => b.text().includes("pagination.next"));
        expect(nextButton.attributes("disabled")).toBeDefined();
    });
});
