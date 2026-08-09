import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppToggle from "./AppToggle.vue";

describe("AppToggle", () => {
    it("applies bg-accent class when modelValue is true", () => {
        const wrapper = mount(AppToggle, { props: { modelValue: true } });
        expect(wrapper.find("button").classes()).toContain("bg-accent");
    });

    it("applies bg-surface-3 class when modelValue is false", () => {
        const wrapper = mount(AppToggle, { props: { modelValue: false } });
        expect(wrapper.find("button").classes()).toContain("bg-surface-3");
    });

    it("applies disabled state and opacity class when disabled=true", () => {
        const wrapper = mount(AppToggle, {
            props: { modelValue: false, disabled: true },
        });
        expect(wrapper.find("button").element.disabled).toBe(true);
        expect(wrapper.find("button").classes()).toContain("opacity-50");
    });

    it("renders hint text under the switch instead of leaking it as an attribute", () => {
        const wrapper = mount(AppToggle, {
            props: { modelValue: false, hint: "Applies on the next publish" },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Applies on the next publish",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("emits update:modelValue with toggled value on click", async () => {
        const wrapper = mount(AppToggle, { props: { modelValue: false } });
        await wrapper.find("button").trigger("click");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
        expect(wrapper.emitted("update:modelValue")[0][0]).toBe(true);
    });

    it("does not emit when disabled and clicked", async () => {
        const wrapper = mount(AppToggle, {
            props: { modelValue: false, disabled: true },
        });
        await wrapper.find("button").trigger("click");
        expect(wrapper.emitted("update:modelValue")).toBeFalsy();
    });
});
