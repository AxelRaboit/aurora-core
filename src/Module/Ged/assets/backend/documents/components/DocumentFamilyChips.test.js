import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import DocumentFamilyChips from "./DocumentFamilyChips.vue";

const members = [
    { id: 1, label: null, title: "Carte", original: true, usageCount: 2 },
    {
        id: 2,
        label: "jaune",
        title: "Carte jaune",
        original: false,
        usageCount: 0,
    },
    {
        id: 3,
        label: "en",
        title: "Carte anglaise",
        original: false,
        usageCount: 0,
    },
];

function mountChips(modelValue = null) {
    return mount(DocumentFamilyChips, {
        props: { members, modelValue },
        global: { plugins: [createTestI18n()] },
    });
}

describe("DocumentFamilyChips", () => {
    it("draws a colour as a dot and any other label as text", () => {
        const buttons = mountChips().findAll("button");

        expect(buttons[1].attributes("style")).toContain("background-color");
        expect(buttons[1].text()).toBe("");
        expect(buttons[2].text()).toBe("en");
    });

    it("marks the original as the member shown until another is chosen", () => {
        const buttons = mountChips().findAll("button");

        expect(buttons[0].attributes("aria-pressed")).toBe("true");
        expect(buttons[1].attributes("aria-pressed")).toBe("false");
    });

    it("chooses a member to show", async () => {
        const wrapper = mountChips();

        await wrapper.findAll("button")[1].trigger("click");

        expect(wrapper.emitted("update:modelValue")?.[0]).toEqual([2]);
    });

    it("marks a member used somewhere", () => {
        const buttons = mountChips().findAll("button");

        expect(buttons[0].find(".bg-success").exists()).toBe(true);
        expect(buttons[1].find(".bg-success").exists()).toBe(false);
    });
});
