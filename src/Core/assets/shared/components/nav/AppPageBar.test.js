import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppPageBar from "./AppPageBar.vue";

describe("AppPageBar", () => {
    it("puts the back link first and the commands in a group pushed right", () => {
        const wrapper = mount(AppPageBar, {
            props: { backHref: "/suite/posts", backLabel: "Publications" },
            slots: { default: '<button data-test="save">Enregistrer</button>' },
        });
        const back = wrapper.find('a[href="/suite/posts"]');
        expect(back.exists()).toBe(true);
        expect(back.text()).toBe("Publications");

        const group = wrapper.find('[data-test="save"]').element.parentElement;
        expect(group.className).toContain("ml-auto");
        expect(wrapper.element.firstElementChild).toBe(back.element);
    });

    it("draws no back link without a label", () => {
        const wrapper = mount(AppPageBar, {
            slots: { default: "<button>Présenter</button>" },
        });
        expect(wrapper.find("a").exists()).toBe(false);
    });
});
