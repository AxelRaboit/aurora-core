import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppNoData from "./AppNoData.vue";

describe("AppNoData", () => {
    it("renders the default message", () => {
        const wrapper = mount(AppNoData);
        expect(wrapper.text()).toContain("Aucune donnée à afficher.");
    });

    it("renders a custom message", () => {
        const wrapper = mount(AppNoData, {
            props: { message: "No results found." },
        });
        expect(wrapper.text()).toContain("No results found.");
    });

    it("renders the inbox icon", () => {
        const wrapper = mount(AppNoData);
        expect(wrapper.find("svg").exists()).toBe(true);
    });

    it("renders the message inside a <p> tag", () => {
        const wrapper = mount(AppNoData, {
            props: { message: "Empty list." },
        });
        expect(wrapper.find("p").text()).toBe("Empty list.");
    });

    it("renders hint text under the message instead of leaking it as an attribute", () => {
        const wrapper = mount(AppNoData, {
            props: { message: "No menus yet.", hint: "Create one to start." },
        });
        expect(wrapper.find("p.text-muted").text()).toBe(
            "Create one to start.",
        );
        expect(wrapper.attributes("hint")).toBeUndefined();
    });

    it("has centering layout classes", () => {
        const wrapper = mount(AppNoData);
        const div = wrapper.find("div");
        expect(div.classes()).toContain("flex");
        expect(div.classes()).toContain("items-center");
        expect(div.classes()).toContain("justify-center");
    });
});
