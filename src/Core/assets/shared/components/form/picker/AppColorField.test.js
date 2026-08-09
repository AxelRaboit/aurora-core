import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppColorField from "./AppColorField.vue";

describe("AppColorField", () => {
    it("renders label text", () => {
        const wrapper = mount(AppColorField, {
            props: { label: "Brand color", modelValue: "" },
        });
        expect(wrapper.text()).toContain("Brand color");
    });

    it("renders error message when error prop is set", () => {
        const wrapper = mount(AppColorField, {
            props: { modelValue: "", error: "Color required" },
        });
        expect(wrapper.find("p.text-rose-400").exists()).toBe(true);
        expect(wrapper.find("p.text-rose-400").text()).toBe("Color required");
    });

    it("does not render error paragraph when error is empty", () => {
        const wrapper = mount(AppColorField, { props: { modelValue: "" } });
        expect(wrapper.find("p.text-rose-400").exists()).toBe(false);
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppColorField, {
            props: { modelValue: "", hint: "Used for buttons and links" },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Used for buttons and links",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("displays hex value when showHex is true and modelValue is set", () => {
        const wrapper = mount(AppColorField, {
            props: { modelValue: "#3b82f6", showHex: true },
        });
        expect(wrapper.text()).toContain("#3b82f6");
    });

    it("contains a color input via AppColorSwatch", () => {
        const wrapper = mount(AppColorField, {
            props: { modelValue: "#ff0000" },
        });
        expect(wrapper.find("input[type='color']").exists()).toBe(true);
    });
});
