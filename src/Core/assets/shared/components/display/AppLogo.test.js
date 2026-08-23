import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppLogo from "./AppLogo.vue";

describe("AppLogo", () => {
    it("renders an SVG element", () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.find("svg").exists()).toBe(true);
    });

    it("uses default size of 40", () => {
        const wrapper = mount(AppLogo);
        const svg = wrapper.find("svg");
        expect(svg.attributes("width")).toBe("40");
        expect(svg.attributes("height")).toBe("40");
    });

    it("applies custom size prop to width and height", () => {
        const wrapper = mount(AppLogo, { props: { size: 64 } });
        const svg = wrapper.find("svg");
        expect(svg.attributes("width")).toBe("64");
        expect(svg.attributes("height")).toBe("64");
    });

    it("contains the letter V as the logo mark", () => {
        const wrapper = mount(AppLogo);
        expect(wrapper.find("text").text()).toBe("V");
    });

    it("renders a linearGradient with a unique id", () => {
        const wrapper = mount(AppLogo);
        const gradient = wrapper.find("linearGradient");
        expect(gradient.exists()).toBe(true);
        expect(gradient.attributes("id")).toMatch(/^aurora-bg-\d+$/);
    });

    /**
     * A class from the parent has to land on the svg.
     *
     * It did not, and silently: the template opened with a comment *before* the
     * root element, which gives the component two root nodes, and Vue cannot pass
     * a parent's attributes to a component with no single root. `AppSidemenu`
     * passes `class="shrink-0"` here, so the logo could squash when the sidebar
     * narrowed - no warning, no error, just a class that evaporated.
     *
     * Guarding it with a test rather than a comment, because the mistake is one
     * character of formatting and reads as tidy.
     */
    it("keeps a class the parent passes it", () => {
        const wrapper = mount(AppLogo, { attrs: { class: "shrink-0" } });

        expect(wrapper.classes()).toContain("shrink-0");
        // Its own class survives the merge rather than being replaced.
        expect(wrapper.classes()).toContain("text-accent-500");
    });

    /** One root node, which is what makes the above possible. */
    it("has a single root element", () => {
        expect(mount(AppLogo).element.nodeType).toBe(Node.ELEMENT_NODE);
    });
});
