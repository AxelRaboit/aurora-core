import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppSiteLogo from "./AppSiteLogo.vue";

describe("AppSiteLogo", () => {
    it("shows the site's logo alone, in both modes, without a dark version", () => {
        const wrapper = mount(AppSiteLogo, { props: { url: "/logo.png" } });

        const light = wrapper.find("[data-site-logo-light]");
        expect(light.attributes("src")).toBe("/logo.png");
        expect(light.classes()).not.toContain("dark:hidden");
        expect(wrapper.find("[data-site-logo-dark]").exists()).toBe(false);
    });

    /**
     * Both pictures are in the page and CSS picks one: the light/dark button
     * swaps them at once, without a script watching the mode.
     */
    it("swaps to the dark version in dark mode", () => {
        const wrapper = mount(AppSiteLogo, {
            props: { url: "/logo.png", darkUrl: "/logo-dark.png" },
        });

        expect(wrapper.find("[data-site-logo-light]").classes()).toContain(
            "dark:hidden",
        );
        const dark = wrapper.find("[data-site-logo-dark]");
        expect(dark.attributes("src")).toBe("/logo-dark.png");
        expect(dark.classes()).toEqual(
            expect.arrayContaining(["hidden", "dark:block"]),
        );
    });

    it("draws Aurora's mark when the site has no logo", () => {
        const wrapper = mount(AppSiteLogo);

        expect(wrapper.find("img").exists()).toBe(false);
        expect(wrapper.find("svg").exists()).toBe(true);
    });

    /** A dark version alone stands in for Aurora's mark in dark mode only. */
    it("keeps Aurora's mark in light mode when only a dark version is set", () => {
        const wrapper = mount(AppSiteLogo, {
            props: { darkUrl: "/logo-dark.png" },
        });

        expect(wrapper.find("svg").classes()).toContain("dark:hidden");
        expect(wrapper.find("[data-site-logo-dark]").exists()).toBe(true);
    });
});
