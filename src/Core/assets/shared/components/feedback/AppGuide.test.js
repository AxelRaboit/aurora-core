import { describe, it, expect, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";
import { nextTick } from "vue";
import AppGuide from "./AppGuide.vue";
import { useGuidePreference } from "@/shared/composables/useGuidePreference.js";

/**
 * L'encart « Comment ça marche ».
 *
 * Ce qui se casserait sans bruit : le choix commun. Replier un encart doit
 * les replier tous, et le choix doit survivre à la visite ; sinon le lecteur
 * qui connaît l'outil referme vingt encarts à la main.
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
});
