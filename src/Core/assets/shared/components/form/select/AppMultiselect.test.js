import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppMultiselect from "./AppMultiselect.vue";

const i18n = createTestI18n({}, "en");

const options = [
    { value: "fr", label: "French" },
    { value: "en", label: "English" },
    { value: "de", label: "German" },
];

describe("AppMultiselect", () => {
    it("renders the multiselect container", () => {
        const wrapper = mount(AppMultiselect, {
            props: { modelValue: null, options },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find(".multiselect").exists()).toBe(true);
    });

    it("applies multiselect--error class when error prop is set", () => {
        const wrapper = mount(AppMultiselect, {
            props: { modelValue: null, options, error: "Required" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find(".multiselect--error").exists()).toBe(true);
    });

    it("renders error paragraph when error is set", () => {
        const wrapper = mount(AppMultiselect, {
            props: { modelValue: null, options, error: "Pick one" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-red-500").text()).toBe("Pick one");
    });

    it("does not render error paragraph when error is empty", () => {
        const wrapper = mount(AppMultiselect, {
            props: { modelValue: null, options },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-red-500").exists()).toBe(false);
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppMultiselect, {
            props: {
                modelValue: null,
                options,
                hint: "Search by name or code",
            },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Search by name or code",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("renders label when label prop is set", () => {
        const wrapper = mount(AppMultiselect, {
            props: { modelValue: null, options, label: "Language" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.text()).toContain("Language");
    });
});
