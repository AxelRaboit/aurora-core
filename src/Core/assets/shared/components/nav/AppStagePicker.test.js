import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppStagePicker from "./AppStagePicker.vue";

const stages = ["draft", "active", "closed"];
const stageLabel = (stage) => stage.toUpperCase();
const stageBadgeClass = (stage) => `badge-${stage}`;

function mountPicker(props = {}) {
    return mount(AppStagePicker, {
        props: {
            modelValue: "draft",
            stages,
            labelFn: stageLabel,
            badgeFn: stageBadgeClass,
            ...props,
        },
    });
}

describe("AppStagePicker", () => {
    it("renders one button per stage", () => {
        const wrapper = mountPicker();
        expect(wrapper.findAll("button").length).toBe(stages.length);
    });

    it("uses labelFn to display stage labels", () => {
        const wrapper = mountPicker();
        const buttons = wrapper.findAll("button");
        expect(buttons[0].text()).toBe("DRAFT");
        expect(buttons[1].text()).toBe("ACTIVE");
    });

    it("applies badgeFn class to the active stage button", () => {
        const wrapper = mountPicker({ modelValue: "active" });
        const activeButton = wrapper.findAll("button")[1];
        expect(activeButton.classes()).toContain("badge-active");
    });

    it("emits update:modelValue with the clicked stage", async () => {
        const wrapper = mountPicker({ modelValue: "draft" });
        await wrapper.findAll("button")[2].trigger("click");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
        expect(wrapper.emitted("update:modelValue")[0]).toEqual(["closed"]);
    });

    it("disables all buttons when disabled prop is true", () => {
        const wrapper = mountPicker({ disabled: true });
        const buttons = wrapper.findAll("button");
        buttons.forEach((button) => {
            expect(button.attributes("disabled")).toBeDefined();
        });
    });
});
