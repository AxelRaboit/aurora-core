import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppImage from "./AppImage.vue";

describe("AppImage", () => {
    it("renders an img element when src is provided", () => {
        const wrapper = mount(AppImage, {
            props: { src: "https://example.com/image.jpg", alt: "test" },
        });
        expect(wrapper.find("img").exists()).toBe(true);
        expect(wrapper.find("img").attributes("src")).toBe(
            "https://example.com/image.jpg",
        );
    });

    it("shows fallback svg icon when src is null", () => {
        const wrapper = mount(AppImage, { props: { src: null } });
        expect(wrapper.find("img").exists()).toBe(false);
        expect(wrapper.find("svg").exists()).toBe(true);
    });

    it("does not show fallback icon when fallbackIcon=false", () => {
        const wrapper = mount(AppImage, {
            props: { src: null, fallbackIcon: false },
        });
        expect(wrapper.find("img").exists()).toBe(false);
        expect(wrapper.find("svg").exists()).toBe(false);
    });

    it("sets object-position style from focalPoint prop", () => {
        const wrapper = mount(AppImage, {
            props: {
                src: "https://example.com/img.jpg",
                focalPoint: "30% 70%",
            },
        });
        const img = wrapper.find("img");
        expect(img.attributes("style")).toContain("object-position: 30% 70%");
    });

    it("applies rounded class to outer div", () => {
        const wrapper = mount(AppImage, {
            props: {
                src: "https://example.com/img.jpg",
                rounded: "rounded-lg",
            },
        });
        expect(wrapper.find("div").classes()).toContain("rounded-lg");
    });

    it("re-renders the <img> after an error when a new src is provided", async () => {
        const wrapper = mount(AppImage, {
            props: { src: "https://example.com/missing.jpg" },
        });

        // Simulate the first image failing to load - Error pruned the <img>.
        await wrapper.find("img").trigger("error");
        expect(wrapper.find("img").exists()).toBe(false);

        // Picking a new media should reset the status and re-render the <img>.
        await wrapper.setProps({ src: "https://example.com/new.jpg" });
        expect(wrapper.find("img").exists()).toBe(true);
        expect(wrapper.find("img").attributes("src")).toBe(
            "https://example.com/new.jpg",
        );
    });
});
