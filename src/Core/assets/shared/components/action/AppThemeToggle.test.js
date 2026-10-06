import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { ref } from "vue";

const toggleMock = vi.fn();
const themeRef = ref("light");

vi.mock("@/shared/composables/useTheme.js", () => ({
    useTheme: () => ({ theme: themeRef, toggle: toggleMock }),
}));

import AppThemeToggle from "./AppThemeToggle.vue";

describe("AppThemeToggle", () => {
    it("renders a button with type=button", () => {
        const wrapper = mount(AppThemeToggle);
        const button = wrapper.find("button");
        expect(button.exists()).toBe(true);
        expect(button.attributes("type")).toBe("button");
    });

    it("renders one svg icon", () => {
        themeRef.value = "light";
        const wrapper = mount(AppThemeToggle);
        expect(wrapper.findAll("svg").length).toBe(1);
    });

    it("renders a different icon when theme is dark", async () => {
        themeRef.value = "light";
        const wrapperLight = mount(AppThemeToggle);
        const lightHtml = wrapperLight.find("svg").html();

        themeRef.value = "dark";
        const wrapperDark = mount(AppThemeToggle);
        const darkHtml = wrapperDark.find("svg").html();

        expect(lightHtml).not.toBe(darkHtml);
    });

    it("calls toggle when clicked", async () => {
        toggleMock.mockClear();
        const wrapper = mount(AppThemeToggle);
        await wrapper.find("button").trigger("click");
        expect(toggleMock).toHaveBeenCalledOnce();
    });
});
