import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppButton from "./AppButton.vue";

describe("AppButton", () => {
    it("renders slot content", () => {
        const wrapper = mount(AppButton, {
            slots: { default: "Click me" },
        });
        expect(wrapper.text()).toContain("Click me");
    });

    it("applies primary variant classes", () => {
        const wrapper = mount(AppButton, {
            props: { variant: "primary" },
        });
        expect(wrapper.find("button").classes()).toContain("bg-accent-600");
    });

    // The ghost carries a surface below `sm` and gives it back above: with a
    // finger, a full-width button without a background reads as a line of text.
    it("applies ghost variant classes", () => {
        const wrapper = mount(AppButton, {
            props: { variant: "ghost" },
        });
        expect(wrapper.find("button").classes()).toContain("bg-surface-2");
        expect(wrapper.find("button").classes()).toContain("sm:bg-transparent");
        expect(wrapper.find("button").classes()).toContain("text-secondary");
    });

    it("shows loading spinner when loading=true", () => {
        const wrapper = mount(AppButton, {
            props: { loading: true },
        });
        // Loader2 renders an SVG with animate-spin class
        expect(wrapper.find("svg.animate-spin").exists()).toBe(true);
    });

    it("is disabled when loading=true", () => {
        const wrapper = mount(AppButton, {
            props: { loading: true },
        });
        expect(wrapper.find("button").attributes("disabled")).toBeDefined();
    });

    // 02/10/2026: a bar command turns icon only below `sm`, without losing its
    // name or its background.
    it("keeps the label readable when icon-only on phone", () => {
        const wrapper = mount(AppButton, {
            props: {
                variant: "secondary",
                label: "Enregistrer",
                iconOnlyOnPhone: true,
            },
            slots: { default: "<svg data-icon />" },
        });
        const label = wrapper.find("span");
        expect(label.text()).toBe("Enregistrer");
        expect(label.classes()).toEqual(
            expect.arrayContaining(["sr-only", "sm:not-sr-only"]),
        );
        expect(wrapper.attributes("title")).toBe("Enregistrer");
        expect(wrapper.classes()).toEqual(
            expect.arrayContaining(["max-sm:size-9.5", "border"]),
        );
    });

    it("stays square at every width with iconOnly", () => {
        const wrapper = mount(AppButton, {
            props: { variant: "secondary", label: "Favori", iconOnly: true },
        });
        expect(wrapper.find("span").classes()).toEqual(["sr-only"]);
        expect(wrapper.classes()).toEqual(
            expect.arrayContaining(["size-9.5", "p-0"]),
        );
        expect(wrapper.classes()).not.toContain("px-4");
    });

    // All the buttons of one size have the same height: a primary carries a
    // transparent border to match the secondary placed next to it.
    it("gives filled variants a transparent border", () => {
        for (const variant of ["primary", "danger", "accent"]) {
            const classes = mount(AppButton, { props: { variant } }).classes();
            expect(classes).toEqual(
                expect.arrayContaining(["border", "border-transparent"]),
            );
        }
    });

    it("does not let the size padding override the icon variant", () => {
        const wrapper = mount(AppButton, {
            props: { variant: "icon", size: "sm" },
        });
        expect(wrapper.classes()).not.toContain("px-3");
    });
});
