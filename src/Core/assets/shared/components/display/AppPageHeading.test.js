import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import AppPageHeading from "./AppPageHeading.vue";
import AppListToolbar from "../list/AppListToolbar.vue";

describe("AppPageHeading", () => {
    it("names the screen and says what it holds", () => {
        const wrapper = mount(AppPageHeading, {
            props: { title: "Publications", subtitle: "49 publications" },
        });

        expect(wrapper.find("h2").text()).toBe("Publications");
        expect(wrapper.text()).toContain("49 publications");
    });

    it("draws no line when there is nothing to say", () => {
        expect(
            mount(AppPageHeading, { props: { title: "Publications" } })
                .find("p")
                .exists(),
        ).toBe(false);
    });
});

describe("AppListToolbar with a title", () => {
    it("opens with the heading and moves the commands beside it", () => {
        const wrapper = mount(AppListToolbar, {
            props: { title: "Publications" },
            slots: {
                default: "<input data-search>",
                actions: "<button data-new>Nouvelle</button>",
            },
        });

        const heading = wrapper.find("[data-page-heading]");
        expect(heading.exists()).toBe(true);
        expect(heading.find("[data-new]").exists()).toBe(true);
        expect(wrapper.find("[data-search]").exists()).toBe(true);
    });

    it("draws as it always did without a title", () => {
        const wrapper = mount(AppListToolbar, {
            slots: { default: "<input data-search>" },
        });

        expect(wrapper.find("[data-page-heading]").exists()).toBe(false);
        expect(wrapper.find("[data-search]").exists()).toBe(true);
    });
});
