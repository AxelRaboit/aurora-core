import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppInput from "./AppInput.vue";

const i18n = createTestI18n({}, "en");

describe("AppInput", () => {
    it("renders the input element", () => {
        const wrapper = mount(AppInput, { global: { plugins: [i18n] } });
        expect(wrapper.find("input").exists()).toBe(true);
    });

    it("reflects placeholder prop on the input", () => {
        const wrapper = mount(AppInput, {
            props: { placeholder: "Enter your name" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("input").attributes("placeholder")).toBe(
            "Enter your name",
        );
    });

    it("renders label text", () => {
        const wrapper = mount(AppInput, {
            props: { label: "Full name" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.text()).toContain("Full name");
    });

    it("applies error border class when error prop is set", () => {
        const wrapper = mount(AppInput, {
            props: { error: "shared.common.error" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("input").classes()).toContain("border-red-500");
    });

    it("emits update:modelValue with input value", async () => {
        const wrapper = mount(AppInput, {
            props: { modelValue: "" },
            global: { plugins: [i18n] },
        });
        const input = wrapper.find("input");
        await input.setValue("hello");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
        expect(wrapper.emitted("update:modelValue")[0][0]).toBe("hello");
    });

    it("renders suffix slot content and pads the input for it", () => {
        const wrapper = mount(AppInput, {
            slots: { suffix: "<button>lock</button>" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("button").exists()).toBe(true);
        expect(wrapper.find("input").classes()).toContain("pr-10");
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppInput, {
            props: { hint: "Lowercase letters and dashes only" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Lowercase letters and dashes only",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("applies the readonly attribute to the input when readonly is set", () => {
        const wrapper = mount(AppInput, {
            props: { readonly: true },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("input").attributes("readonly")).toBeDefined();
    });
});
