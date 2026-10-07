import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppSelect from "./AppSelect.vue";

const i18n = createTestI18n({}, "en");

const options = [
    { value: "cat", label: "Cat" },
    { value: "dog", label: "Dog" },
    { value: "bird", label: "Bird" },
];

function mountSelect(props) {
    return mount(AppSelect, {
        props: { modelValue: "", options, ...props },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
}

function optionLabels(wrapper) {
    return wrapper
        .findComponent({ name: "vue-multiselect" })
        .props("options")
        .map((option) => option.label);
}

describe("AppSelect", () => {
    it("never renders a native select", () => {
        const wrapper = mountSelect();
        expect(wrapper.find("select").exists()).toBe(false);
        expect(wrapper.find(".multiselect").exists()).toBe(true);
        wrapper.unmount();
    });

    it("lists the options, the placeholder first so a filter can be reset", () => {
        const wrapper = mountSelect({ placeholder: "Every animal" });
        expect(optionLabels(wrapper)).toEqual([
            "Every animal",
            "Cat",
            "Dog",
            "Bird",
        ]);
        wrapper.unmount();
    });

    it("accepts an object map of value to label", () => {
        const wrapper = mountSelect({ options: { cat: "Cat", dog: "Dog" } });
        expect(optionLabels(wrapper)).toEqual(["Cat", "Dog"]);
        wrapper.unmount();
    });

    it("shows the chosen option, numbers matching their string form", () => {
        const wrapper = mountSelect({
            modelValue: 2,
            options: [
                { value: 1, label: "One" },
                { value: 2, label: "Two" },
            ],
        });
        expect(wrapper.find(".multiselect__single").text()).toBe("Two");
        wrapper.unmount();
    });

    it("emits the value as a string, like the native control did", async () => {
        const wrapper = mountSelect({
            options: [
                { value: 1, label: "One" },
                { value: 2, label: "Two" },
            ],
        });
        wrapper
            .findComponent({ name: "vue-multiselect" })
            .vm.$emit("update:modelValue", { value: "2", label: "Two" });
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted("update:modelValue")[0][0]).toBe("2");
        wrapper.unmount();
    });

    it("emits an empty string when the placeholder is chosen back", async () => {
        const wrapper = mountSelect({
            modelValue: "dog",
            placeholder: "Every animal",
        });
        wrapper
            .findComponent({ name: "vue-multiselect" })
            .vm.$emit("update:modelValue", {
                value: "",
                label: "Every animal",
            });
        await wrapper.vm.$nextTick();
        expect(wrapper.emitted("update:modelValue")[0][0]).toBe("");
        wrapper.unmount();
    });

    it("can be disabled", () => {
        const wrapper = mountSelect({ disabled: true });
        expect(wrapper.find(".multiselect--disabled").exists()).toBe(true);
        wrapper.unmount();
    });

    it("searches only long lists", () => {
        const short = mountSelect();
        expect(short.find("input.multiselect__input").exists()).toBe(false);
        short.unmount();

        const many = Array.from({ length: 12 }, (_, index) => ({
            value: `o${index}`,
            label: `Option ${index}`,
        }));
        const long = mountSelect({ options: many });
        expect(long.find("input.multiselect__input").exists()).toBe(true);
        long.unmount();
    });

    it("shows the error and the hint", () => {
        const wrapper = mountSelect({
            error: "Required",
            hint: "Pick the closest match",
        });
        expect(wrapper.find(".multiselect--error").exists()).toBe(true);
        expect(wrapper.find("p.text-red-500").text()).toBe("Required");
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Pick the closest match",
        );
        wrapper.unmount();
    });
});
