import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppTagsInput from "./AppTagsInput.vue";

const i18n = createTestI18n({}, "en");

describe("AppTagsInput", () => {
    it("renders existing tags", () => {
        const wrapper = mount(AppTagsInput, {
            props: { modelValue: ["vue", "react", "svelte"] },
            global: { plugins: [i18n] },
        });
        const spans = wrapper.findAll("span.inline-flex");
        expect(spans.length).toBe(3);
        expect(spans[0].text()).toContain("vue");
    });

    it("emits update:modelValue with new tag on Enter key", async () => {
        const wrapper = mount(AppTagsInput, {
            props: { modelValue: [] },
            global: { plugins: [i18n] },
        });
        const input = wrapper.find("input[type='text']");
        await input.setValue("newtag");
        await input.trigger("keydown", { key: "Enter" });
        const emitted = wrapper.emitted("update:modelValue");
        expect(emitted).toBeTruthy();
        expect(emitted[0][0]).toContain("newtag");
    });

    it("emits update:modelValue without removed tag when remove button is clicked", async () => {
        const wrapper = mount(AppTagsInput, {
            props: { modelValue: ["vue", "react"] },
            global: { plugins: [i18n] },
        });
        // First remove button corresponds to first tag "vue"
        await wrapper.find("button").trigger("click");
        const emitted = wrapper.emitted("update:modelValue");
        expect(emitted).toBeTruthy();
        expect(emitted[0][0]).not.toContain("vue");
        expect(emitted[0][0]).toContain("react");
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppTagsInput, {
            props: { modelValue: [], hint: "Press Enter after each tag" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Press Enter after each tag",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("applies error border class when error prop is set", () => {
        const wrapper = mount(AppTagsInput, {
            props: { modelValue: [], error: "Tags required" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("div.flex.flex-wrap").classes()).toContain(
            "border-rose-400",
        );
    });

    it("renders error message text when error prop is set", () => {
        const wrapper = mount(AppTagsInput, {
            props: { modelValue: [], error: "At least one tag" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-rose-500").text()).toBe("At least one tag");
    });
});
