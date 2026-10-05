import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import CalendarEntriesField from "./CalendarEntriesField.vue";

// The date picker reads the colour scheme when it is imported, which jsdom
// cannot answer: replaced, keeping its contract (a value in, a value out).
vi.mock("@/shared/components/form/picker/AppDatePicker.vue", () => ({
    default: {
        name: "AppDatePicker",
        props: ["modelValue", "placeholder"],
        template: "<input />",
    },
}));

const mountField = (props) =>
    mount(CalendarEntriesField, {
        props,
        global: { plugins: [createTestI18n()] },
    });

describe("CalendarEntriesField", () => {
    it("shows one row per publication", () => {
        const wrapper = mountField({
            modelValue:
                "2026-11-03 | Carrousel | Les coulisses\n2026-11-05 | Réel | Une journée",
        });

        expect(wrapper.findAll("[role=group]")).toHaveLength(2);
    });

    it("writes the text back when a subject changes", async () => {
        const wrapper = mountField({
            modelValue: "2026-11-03 | Carrousel | Les coulisses",
        });

        const subject = wrapper
            .findAllComponents({ name: "AppInput" })
            .find((input) => "Les coulisses" === input.props("modelValue"));
        await subject.vm.$emit("update:modelValue", "Le making-of");

        expect(wrapper.emitted("update:modelValue").at(-1)).toEqual([
            "2026-11-03 | Carrousel | Le making-of",
        ]);
    });

    it("starts a new row in the month of the last one", async () => {
        const wrapper = mountField({
            modelValue: "2026-11-03 | Carrousel | Les coulisses",
        });

        await wrapper
            .findAll("button")
            .find((button) => button.text().includes("calendar_entry_add"))
            .trigger("click");

        expect(wrapper.findAll("[role=group]")).toHaveLength(2);
        const pickers = wrapper.findAllComponents({ name: "AppDatePicker" });
        expect(pickers.at(-1).props("modelValue")).toBe("2026-11-01");
    });

    it("suggests formats and networks without forbidding anything else", () => {
        const wrapper = mountField({ modelValue: "" });
        const options = wrapper
            .findAll("datalist option")
            .map((option) => option.attributes("value"));

        expect(options).toEqual(
            expect.arrayContaining([
                "Carrousel",
                "Post",
                "Réel",
                "Story",
                "Instagram",
            ]),
        );
        const kind = wrapper
            .findAll("input")
            .find((input) => input.attributes("list"));
        expect(kind.attributes("list")).toBe(
            wrapper.find("datalist").attributes("id"),
        );
    });
});
