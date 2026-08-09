import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppTextarea from "./AppTextarea.vue";

const i18n = createTestI18n({}, "en");

describe("AppTextarea", () => {
    it("renders a textarea element", () => {
        const wrapper = mount(AppTextarea, { global: { plugins: [i18n] } });
        expect(wrapper.find("textarea").exists()).toBe(true);
    });

    it("reflects placeholder prop on the textarea", () => {
        const wrapper = mount(AppTextarea, {
            props: { placeholder: "Write something…" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("textarea").attributes("placeholder")).toBe(
            "Write something…",
        );
    });

    it("sets the rows attribute from rows prop", () => {
        const wrapper = mount(AppTextarea, {
            props: { rows: 6 },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("textarea").attributes("rows")).toBe("6");
    });

    it("applies error border class when error prop is set", () => {
        const wrapper = mount(AppTextarea, {
            props: { error: "Required" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("textarea").classes()).toContain("border-red-500");
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppTextarea, {
            props: { hint: "Around 155 characters reads best" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Around 155 characters reads best",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("emits update:modelValue with new value on input", async () => {
        const wrapper = mount(AppTextarea, {
            props: { modelValue: "" },
            global: { plugins: [i18n] },
        });
        await wrapper.find("textarea").setValue("some text");
        expect(wrapper.emitted("update:modelValue")).toBeTruthy();
        expect(wrapper.emitted("update:modelValue")[0][0]).toBe("some text");
    });
});
