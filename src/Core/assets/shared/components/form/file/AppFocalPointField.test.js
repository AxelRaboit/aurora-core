import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import AppFocalPointField from "./AppFocalPointField.vue";

const i18n = createTestI18n({}, "en");

const SRC = "https://example.com/photo.jpg";

describe("AppFocalPointField", () => {
    it("renders nothing without a picture to aim at", () => {
        const wrapper = mount(AppFocalPointField, {
            props: { src: "" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.find("img").exists()).toBe(false);
    });

    it("renders hint text under the control instead of leaking it as an attribute", () => {
        const wrapper = mount(AppFocalPointField, {
            props: { src: SRC, hint: "Click the part that matters" },
            global: { plugins: [i18n] },
        });
        expect(wrapper.findAll("p.text-muted").map((p) => p.text())).toContain(
            "Click the part that matters",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });
});
