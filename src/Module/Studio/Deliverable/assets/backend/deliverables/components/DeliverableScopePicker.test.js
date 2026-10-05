import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DeliverableScopePicker from "./DeliverableScopePicker.vue";

const i18n = createTestI18n();

describe("DeliverableScopePicker", () => {
    const mountPicker = (props = {}) =>
        mount(DeliverableScopePicker, {
            props: { modelValue: "personal", ...props },
            global: { plugins: [i18n] },
        });

    it("shows both shelves and which one is chosen", () => {
        const buttons = mountPicker().findAll("button");

        expect(buttons).toHaveLength(2);
        expect(
            buttons.map((button) => button.attributes("aria-pressed")),
        ).toEqual(["true", "false"]);
    });

    it("emits the shelf that is clicked", async () => {
        const wrapper = mountPicker();

        await wrapper.findAll("button")[1].trigger("click");

        expect(wrapper.emitted("update:modelValue")).toEqual([["shared"]]);
    });

    it("reads without moving when the choice is not the reader's to make", async () => {
        const wrapper = mountPicker({ disabled: true });

        expect(
            wrapper
                .findAll("button")
                .every((button) => button.attributes("disabled") !== undefined),
        ).toBe(true);
        await wrapper.findAll("button")[1].trigger("click");
        expect(wrapper.emitted("update:modelValue")).toBeUndefined();
    });
});
