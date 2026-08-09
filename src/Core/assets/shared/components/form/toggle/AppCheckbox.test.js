import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppCheckbox from "./AppCheckbox.vue";

describe("AppCheckbox", () => {
    it("renders unchecked by default", () => {
        const wrapper = mount(AppCheckbox);
        const input = wrapper.find("input[type='checkbox']");
        expect(input.exists()).toBe(true);
        expect(input.element.checked).toBe(false);
    });

    it("renders checked when modelValue is true", () => {
        const wrapper = mount(AppCheckbox, { props: { modelValue: true } });
        const input = wrapper.find("input[type='checkbox']");
        expect(input.element.checked).toBe(true);
    });

    it("applies disabled state", () => {
        const wrapper = mount(AppCheckbox, { props: { disabled: true } });
        const input = wrapper.find("input[type='checkbox']");
        expect(input.element.disabled).toBe(true);
        expect(wrapper.find("label").classes()).toContain("opacity-50");
    });

    it("renders label from prop", () => {
        const wrapper = mount(AppCheckbox, {
            props: { label: "Accept terms" },
        });
        expect(wrapper.text()).toContain("Accept terms");
    });

    it("renders hint text under the box instead of leaking it as an attribute", () => {
        const wrapper = mount(AppCheckbox, {
            props: { hint: "Archives get their own listing page" },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Archives get their own listing page",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("keeps the hint outside the label so clicking it does not toggle", () => {
        const wrapper = mount(AppCheckbox, {
            props: { hint: "Not a click target" },
        });
        expect(wrapper.find("label p").exists()).toBe(false);
    });

    it("emits update:modelValue on change", async () => {
        const wrapper = mount(AppCheckbox, { props: { modelValue: false } });
        const input = wrapper.find("input[type='checkbox']");
        await input.trigger("change");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
    });
});
