import { describe, it, expect, beforeEach } from "vitest";
import { mount } from "@vue/test-utils";
import AppGuide from "./AppGuide.vue";

/**
 * L'encart « Comment ça marche ».
 *
 * Ce qui se casserait sans bruit : le choix du lecteur. Un encart replié qui
 * se rouvre à chaque visite est un encart qu'on finit par ne plus lire.
 */
describe("AppGuide", () => {
    beforeEach(() => window.localStorage.clear());

    it("opens as asked when the reader has not chosen yet", () => {
        const wrapper = mount(AppGuide, {
            props: { title: "Comment ça marche", open: false },
            slots: { default: "<p>Étape</p>" },
        });

        expect(wrapper.find("details").element.open).toBe(false);
        expect(wrapper.text()).toContain("Comment ça marche");
    });

    it("remembers a choice under its key, over what the caller asks", async () => {
        const first = mount(AppGuide, {
            props: { title: "Guide", open: true, storageKey: "essai" },
        });
        const details = first.find("details");
        details.element.open = false;
        await details.trigger("toggle");

        const again = mount(AppGuide, {
            props: { title: "Guide", open: true, storageKey: "essai" },
        });
        expect(again.find("details").element.open).toBe(false);
    });
});
