import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppImagePreview from "./AppImagePreview.vue";

describe("AppImagePreview", () => {
    it("renders an img with the given src and alt", () => {
        const wrapper = mount(AppImagePreview, {
            props: { src: "https://example.com/preview.jpg", alt: "Preview" },
        });
        const image = wrapper.find("img");
        expect(image.exists()).toBe(true);
        expect(image.attributes("src")).toBe("https://example.com/preview.jpg");
        expect(image.attributes("alt")).toBe("Preview");
    });

    it("applies max-h-64 class by default (size=md)", () => {
        const wrapper = mount(AppImagePreview, {
            props: { src: "https://example.com/preview.jpg" },
        });
        expect(wrapper.find("img").classes()).toContain("max-h-64");
    });

    it("applies max-h-48 class when size=sm", () => {
        const wrapper = mount(AppImagePreview, {
            props: { src: "https://example.com/preview.jpg", size: "sm" },
        });
        expect(wrapper.find("img").classes()).toContain("max-h-48");
    });

    it("applies max-h-80 class when size=lg", () => {
        const wrapper = mount(AppImagePreview, {
            props: { src: "https://example.com/preview.jpg", size: "lg" },
        });
        expect(wrapper.find("img").classes()).toContain("max-h-80");
    });

    it("applies w-full class when full=true", () => {
        const wrapper = mount(AppImagePreview, {
            props: { src: "https://example.com/preview.jpg", full: true },
        });
        const image = wrapper.find("img");
        expect(image.classes()).toContain("w-full");
        expect(image.classes()).not.toContain("rounded-lg");
    });
});
