import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppAvatar from "./AppAvatar.vue";

describe("AppAvatar", () => {
    it("renders initials from name prop", () => {
        const wrapper = mount(AppAvatar, { props: { name: "John Doe" } });
        expect(wrapper.find("span").text()).toBe("JD");
    });

    it("renders initials from firstName + lastName when no name given", () => {
        const wrapper = mount(AppAvatar, {
            props: { firstName: "Alice", lastName: "Martin" },
        });
        expect(wrapper.find("span").text()).toBe("AM");
    });

    it("renders an img when photoUrl is provided", () => {
        const wrapper = mount(AppAvatar, {
            props: { name: "John", photoUrl: "https://example.com/avatar.jpg" },
        });
        expect(wrapper.find("img").exists()).toBe(true);
        expect(wrapper.find("img").attributes("src")).toBe(
            "https://example.com/avatar.jpg",
        );
        expect(wrapper.find("span").exists()).toBe(false);
    });

    it("applies solid variant class when variant=solid", () => {
        const wrapper = mount(AppAvatar, {
            props: { name: "Bob", variant: "solid" },
        });
        expect(wrapper.find("div").classes()).toContain("bg-accent-600");
    });

    it("paints the badge in the given colour instead of the variant", () => {
        const wrapper = mount(AppAvatar, {
            props: { name: "Test A", color: "hsl(94 70% 45%)" },
        });
        const badge = wrapper.find("div");
        expect(badge.attributes("style")).toContain("background-color");
        expect(badge.classes()).toContain("text-white");
        expect(badge.classes()).not.toContain("bg-accent-600/20");
    });

    it("keeps the variant and sets no style without a colour", () => {
        const wrapper = mount(AppAvatar, { props: { name: "Bob" } });
        expect(wrapper.find("div").classes()).toContain("bg-accent-600/20");
        expect(wrapper.find("div").attributes("style")).toBeUndefined();
    });

    it("applies custom pixel size via inline style when size is a number", () => {
        const wrapper = mount(AppAvatar, { props: { name: "Test", size: 48 } });
        const style = wrapper.find("div").attributes("style");
        expect(style).toContain("width: 48px");
        expect(style).toContain("height: 48px");
    });
});
