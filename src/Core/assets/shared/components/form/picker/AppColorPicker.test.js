import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppColorPicker from "./AppColorPicker.vue";

const DEFAULT_PRESETS = [
    "#ef4444",
    "#f97316",
    "#f59e0b",
    "#eab308",
    "#84cc16",
    "#22c55e",
    "#10b981",
    "#14b8a6",
    "#06b6d4",
    "#3b82f6",
    "#6366f1",
    "#8b5cf6",
    "#a855f7",
    "#ec4899",
    "#f43f5e",
    "#64748b",
];

describe("AppColorPicker", () => {
    it("renders 16 preset color buttons by default", () => {
        const wrapper = mount(AppColorPicker, { props: { modelValue: null } });
        const buttons = wrapper
            .findAll("button[type='button']")
            .filter((button) => button.attributes("title")?.startsWith("#"));
        expect(buttons.length).toBe(16);
    });

    it("marks the active preset with border-primary class", () => {
        const activeColor = "#3b82f6";
        const wrapper = mount(AppColorPicker, {
            props: { modelValue: activeColor },
        });
        const activeButton = wrapper
            .findAll("button[type='button']")
            .find((button) => button.attributes("title") === activeColor);
        expect(activeButton?.classes()).toContain("border-primary");
    });

    it("non-active presets do not have border-primary class", () => {
        const wrapper = mount(AppColorPicker, {
            props: { modelValue: "#3b82f6" },
        });
        const inactiveButton = wrapper
            .findAll("button[type='button']")
            .find((button) => button.attributes("title") === "#ef4444");
        expect(inactiveButton?.classes()).not.toContain("border-primary");
    });

    it("emits update:modelValue when a preset is clicked", async () => {
        const wrapper = mount(AppColorPicker, { props: { modelValue: null } });
        const firstPreset = wrapper
            .findAll("button[type='button']")
            .find((button) => button.attributes("title")?.startsWith("#"));
        await firstPreset?.trigger("click");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
    });

    it("displays error message when error prop is set", () => {
        const wrapper = mount(AppColorPicker, {
            props: { modelValue: null, error: "Please pick a color" },
        });
        expect(wrapper.find("p.text-red-500").exists()).toBe(true);
        expect(wrapper.find("p.text-red-500").text()).toBe(
            "Please pick a color",
        );
    });
});
