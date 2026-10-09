import { describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";
import AppBetaBadge from "./AppBetaBadge.vue";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

describe("AppBetaBadge", () => {
    it("says beta, and what it means on hover", () => {
        const wrapper = mount(AppBetaBadge);

        expect(wrapper.text()).toBe("shared.common.beta");
        expect(wrapper.attributes("title")).toBe("shared.common.beta_hint");
    });
});
