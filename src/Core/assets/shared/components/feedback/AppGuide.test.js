import { describe, it, expect, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";
import { nextTick } from "vue";
import AppGuide from "./AppGuide.vue";
import { useGuidePreference } from "@/shared/composables/useGuidePreference.js";

/**
 * The "How it works" panel.
 *
 * What would break silently: the shared choice. Collapsing one panel must
 * collapse them all, and the choice must survive the visit; otherwise the
 * reader who knows the tool closes twenty panels by hand.
 */
describe("AppGuide", () => {
    beforeEach(() => useGuidePreference().forget());

    it("follows `open` while the reader has not chosen", () => {
        const wrapper = mount(AppGuide, {
            props: { title: "Comment ça marche", open: false },
            slots: { default: "<p>Étape</p>" },
        });

        expect(wrapper.find("details").element.open).toBe(false);
        expect(wrapper.text()).toContain("Comment ça marche");
    });

    it("folds every guide on the page when one is folded", async () => {
        const first = mount(AppGuide, { props: { title: "Un" } });
        const second = mount(AppGuide, { props: { title: "Deux" } });

        const details = first.find("details");
        details.element.open = false;
        await details.trigger("toggle");
        await nextTick();

        expect(second.find("details").element.open).toBe(false);
    });

    it("keeps the choice for the next visit, over what the caller asks", async () => {
        const first = mount(AppGuide, {
            props: { title: "Guide", open: true },
        });
        const details = first.find("details");
        details.element.open = false;
        await details.trigger("toggle");

        expect(window.localStorage.getItem("aurora.guides.open")).toBe("0");

        const later = mount(AppGuide, {
            props: { title: "Autre", open: true },
        });
        expect(later.find("details").element.open).toBe(false);
    });

    it("opens on the first visit of its screen, and stays folded after", () => {
        const first = mount(AppGuide, {
            props: { title: "Guide", storageKey: "posts" },
        });
        expect(first.find("details").element.open).toBe(true);

        const next = mount(AppGuide, {
            props: { title: "Guide", storageKey: "posts" },
        });
        expect(next.find("details").element.open).toBe(false);

        const elsewhere = mount(AppGuide, {
            props: { title: "Guide", storageKey: "contracts" },
        });
        expect(elsewhere.find("details").element.open).toBe(true);
    });

    it("drops its frame when folded", async () => {
        const wrapper = mount(AppGuide, {
            props: { title: "Guide", open: false },
        });

        expect(wrapper.find("[data-guide]").classes()).not.toContain("border");

        const details = wrapper.find("details");
        details.element.open = true;
        await details.trigger("toggle");

        expect(wrapper.find("[data-guide]").classes()).toContain("border");
        expect(wrapper.find("[data-guide]").classes()).toContain("bg-surface");
        expect(wrapper.find("[data-guide]").classes()).not.toContain(
            "border-dashed",
        );
    });

    it("is rounded unless told otherwise", () => {
        const rounded = mount(AppGuide, { props: { title: "Arrondi" } });
        const flush = mount(AppGuide, {
            props: { title: "Encastré", rounded: false },
        });

        expect(rounded.find("[data-guide]").classes()).toContain("rounded-xl");
        expect(flush.find("[data-guide]").classes()).toContain("rounded-none");
        expect(flush.find("[data-guide]").classes()).not.toContain(
            "rounded-xl",
        );
    });
});
