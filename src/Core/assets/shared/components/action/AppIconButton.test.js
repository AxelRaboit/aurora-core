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
        expect(wrapper.classes().some((c) => c.includes("rose"))).toBe(true);
    });

    /**
     * Trente pixels sous le pouce, l'ancien serrage à la souris : c'est la
     * seule taille, et une valeur inconnue retombe dessus plutôt que de rendre
     * un bouton sans dimensions.
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
});
