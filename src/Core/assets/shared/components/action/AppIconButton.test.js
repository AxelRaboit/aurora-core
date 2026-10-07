import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppIconButton from "./AppIconButton.vue";

describe("AppIconButton", () => {
    it("renders a <button> by default", () => {
        const wrapper = mount(AppIconButton);
        expect(wrapper.element.tagName).toBe("BUTTON");
        expect(wrapper.attributes("type")).toBe("button");
    });

    it("renders an <a> when href is provided", () => {
        const wrapper = mount(AppIconButton, {
            props: { href: "https://example.com" },
        });
        expect(wrapper.element.tagName).toBe("A");
        expect(wrapper.attributes("href")).toBe("https://example.com");
    });

    it("sets aria-label from ariaLabel prop, falling back to title", () => {
        const withAriaLabel = mount(AppIconButton, {
            props: { ariaLabel: "Close dialog", title: "Close" },
        });
        expect(withAriaLabel.attributes("aria-label")).toBe("Close dialog");

        const withTitleOnly = mount(AppIconButton, {
            props: { title: "Settings" },
        });
        expect(withTitleOnly.attributes("aria-label")).toBe("Settings");
    });

    it("applies rose color classes when color='rose'", () => {
        const wrapper = mount(AppIconButton, { props: { color: "rose" } });
        expect(
            wrapper.classes().some((className) => className.includes("rose")),
        ).toBe(true);
    });

    /**
     * Thirty pixels under the thumb, the old tight fit with a mouse: it is the
     * only size, and an unknown value falls back to it rather than rendering a
     * button without dimensions.
     */
    it("gives every button a thumb-sized box below sm", () => {
        const wrapper = mount(AppIconButton);
        expect(wrapper.classes()).toContain("min-h-7.5");
        expect(wrapper.classes()).toContain("sm:min-h-0");
    });

    it("falls back to that size when asked for one that no longer exists", () => {
        const wrapper = mount(AppIconButton, { props: { size: "compact" } });
        expect(wrapper.classes()).toContain("min-h-7.5");
    });

    // Fifteen buttons of the grid editor passed `:icon` and showed up empty.
    it("renders the icon prop when no slot is given", () => {
        const Icon = { template: '<svg data-test="icon" />' };
        const wrapper = mount(AppIconButton, {
            props: { icon: Icon, title: "Monter" },
        });
        expect(wrapper.find('[data-test="icon"]').exists()).toBe(true);
    });

    it("shows its on state and says so", () => {
        const wrapper = mount(AppIconButton, {
            props: { active: true, title: "Panneau" },
        });
        expect(wrapper.classes()).toContain("text-accent-400");
        expect(wrapper.attributes("aria-pressed")).toBe("true");
    });

    it("follows a title that changes with the button's state", async () => {
        const wrapper = mount(AppIconButton, {
            props: { title: "Tout replier", color: "default" },
        });

        await wrapper.setProps({ title: "Tout déplier", color: "rose" });

        expect(wrapper.attributes("aria-label")).toBe("Tout déplier");
        expect(wrapper.classes()).toContain("hover:text-rose-400");
    });
});
