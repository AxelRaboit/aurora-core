import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppMessage from "./AppMessage.vue";

describe("AppMessage", () => {
    it("renders slot content", () => {
        const wrapper = mount(AppMessage, {
            slots: { default: "Something went wrong." },
        });
        expect(wrapper.text()).toContain("Something went wrong.");
    });

    it("defaults to info variant classes", () => {
        const wrapper = mount(AppMessage);
        const div = wrapper.find("div");
        expect(div.classes()).toContain("border-sky-300");
        expect(div.classes()).toContain("bg-sky-50");
        expect(div.classes()).toContain("text-sky-800");
    });

    /**
     * The variant that explains rather than alerts: it is the only one whose
     * classes do not come from a state colour, so the only one that a careless
     * review could align with the others "to match". The test says it is
     * neutral on purpose.
     */
    it("keeps the neutral variant free of any state colour", () => {
        const wrapper = mount(AppMessage, { props: { variant: "neutral" } });

        expect(wrapper.classes().join(" ")).toContain("bg-surface-2/40");
        expect(wrapper.classes().join(" ")).toContain("text-muted");
        expect(wrapper.classes().join(" ")).not.toContain("sky");
    });

    it("applies danger variant classes", () => {
        const wrapper = mount(AppMessage, { props: { variant: "danger" } });
        const div = wrapper.find("div");
        expect(div.classes()).toContain("border-rose-300");
        expect(div.classes()).toContain("bg-rose-50");
        expect(div.classes()).toContain("text-rose-800");
    });

    it("applies success variant classes", () => {
        const wrapper = mount(AppMessage, { props: { variant: "success" } });
        const div = wrapper.find("div");
        expect(div.classes()).toContain("border-emerald-300");
        expect(div.classes()).toContain("bg-emerald-50");
    });

    it("hides the icon when icon prop is false", () => {
        const wrapper = mount(AppMessage, { props: { icon: false } });
        expect(wrapper.find("svg").exists()).toBe(false);
    });

    it("shows default icon when icon prop is true", () => {
        const wrapper = mount(AppMessage, {
            props: { variant: "info", icon: true },
        });
        expect(wrapper.find("svg").exists()).toBe(true);
    });

    it("renders actions slot when provided", () => {
        const wrapper = mount(AppMessage, {
            slots: { actions: "<button>Retry</button>" },
        });
        expect(wrapper.find("button").exists()).toBe(true);
        expect(wrapper.text()).toContain("Retry");
    });

    /**
     * The cross only exists when asked for, and hides nothing by itself: the
     * caller decides for how long.
     */
    it("offers a cross only when dismissible, and leaves the hiding to the caller", async () => {
        expect(mount(AppMessage).find("button").exists()).toBe(false);

        const wrapper = mount(AppMessage, {
            props: { dismissible: true, dismissLabel: "Masquer" },
        });
        const cross = wrapper.find("button");
        expect(cross.attributes("aria-label")).toBe("Masquer");

        await cross.trigger("click");
        expect(wrapper.emitted("dismiss")).toHaveLength(1);
        expect(wrapper.exists()).toBe(true);
    });
});
