import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppAmountInput from "./AppAmountInput.vue";

const i18n = createTestI18n({}, "en");

describe("AppAmountInput", () => {
    it("renders label text", () => {
        const wrapper = mount(AppAmountInput, {
            props: { label: "Amount", modelValue: "" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.text()).toContain("Amount");
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppAmountInput, {
            props: { modelValue: "", hint: "Type 100+50 to add amounts up" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Type 100+50 to add amounts up",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("applies error border class when error prop is set", () => {
        const wrapper = mount(AppAmountInput, {
            props: { modelValue: "", error: "Required" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("input").classes()).toContain("border-red-500");
    });
});
