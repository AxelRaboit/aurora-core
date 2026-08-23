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

    // A page whose entire content is "nothing here yet" is where the button
    // creating the first thing belongs. Four master-detail pages used to keep it
    // in a sidebar that the empty state replaced, so the emptiness was a dead
    // end - nothing on screen but a message.
    it("renders an action when one is given", () => {
        const wrapper = mount(AppNoData, {
            props: { message: "No forms yet." },
            slots: { action: '<button type="button">Create a form</button>' },
        });

        expect(wrapper.find("button").text()).toBe("Create a form");
    });

    it("adds no wrapper for an action it was not given", () => {
        const wrapper = mount(AppNoData);

        expect(wrapper.find("button").exists()).toBe(false);
        // Two children: the icon and the text group. An empty third would put
        // margin under the message for nothing.
        expect(wrapper.find("div").element.children).toHaveLength(2);
    });
});
