import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppSelect from "./AppSelect.vue";

const options = [
    { value: "cat", label: "Cat" },
    { value: "dog", label: "Dog" },
    { value: "bird", label: "Bird" },
];

describe("AppSelect", () => {
    it("renders a select element", () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: "", options },
        });
        expect(wrapper.find("select").exists()).toBe(true);
    });

    it("renders all options from array prop", () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: "", options },
        });
        const optionEls = wrapper.findAll("option");
        expect(optionEls.map((o) => o.text())).toContain("Cat");
        expect(optionEls.map((o) => o.text())).toContain("Dog");
        expect(optionEls.map((o) => o.text())).toContain("Bird");
    });

    it("renders a placeholder option when placeholder prop is set", () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: "", options, placeholder: "Pick one" },
        });
        const first = wrapper.findAll("option")[0];
        expect(first.text()).toBe("Pick one");
        expect(first.attributes("value")).toBe("");
    });

    it("applies error class when error prop is set", () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: "", options, error: "Required" },
        });
        expect(wrapper.find("select").classes()).toContain("border-red-500");
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: "", options, hint: "Pick the closest match" },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Pick the closest match",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("emits update:modelValue on change", async () => {
        const wrapper = mount(AppSelect, {
            props: { modelValue: "", options },
        });
        await wrapper.find("select").setValue("dog");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
        expect(wrapper.emitted("update:modelValue")[0][0]).toBe("dog");
    });
});
