import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import AppActionButton from "./AppActionButton.vue";

describe("AppActionButton", () => {
    it("renders a <button> by default", () => {
        const wrapper = mount(AppActionButton, {
            props: { title: "Supprimer" },
        });

        expect(wrapper.element.tagName).toBe("BUTTON");
        expect(wrapper.attributes("type")).toBe("button");
    });

    // Some actions are navigations — impersonating a user is a link, and should
    // be openable in a new tab like any other.
    it("renders an <a> when given an href", () => {
        const wrapper = mount(AppActionButton, {
            props: { title: "Prendre l'identité", href: "/impersonate/1" },
        });

        expect(wrapper.element.tagName).toBe("A");
        expect(wrapper.attributes("href")).toBe("/impersonate/1");
    });

    /**
     * The rule this component exists for: bold is contrast, not importance.
     * It separates the name of an action from the sentence under it, so with no
     * sentence there is nothing to separate and a lone bold line is shouting.
     */
    it("emboldens the title only when there is a description", () => {
        const withDescription = mount(AppActionButton, {
            props: {
                title: "Supprimer",
                description: "Retire le compte définitivement.",
            },
        });
        const alone = mount(AppActionButton, { props: { title: "Supprimer" } });

        expect(withDescription.get("span > span").classes()).toContain(
            "font-semibold",
        );
        expect(alone.get("span > span").classes()).not.toContain(
            "font-semibold",
        );
    });

    it("shows the description under the title, and nothing when there is none", () => {
        const withDescription = mount(AppActionButton, {
            props: {
                title: "Désactiver",
                description: "Le compte ne pourra plus se connecter.",
            },
        });
        const alone = mount(AppActionButton, {
            props: { title: "Désactiver" },
        });

        expect(withDescription.text()).toContain(
            "Le compte ne pourra plus se connecter.",
        );
        expect(alone.text()).toBe("Désactiver");
    });

    // Mirrors AppIconButton, so an action keeps the meaning it had as a glyph.
    it("carries its colour through, destructive ones included", () => {
        const wrapper = mount(AppActionButton, {
            props: { title: "Supprimer", color: "rose" },
        });

        expect(wrapper.classes().some((c) => c.includes("rose"))).toBe(true);
    });

    it("cannot be pressed when disabled", () => {
        const wrapper = mount(AppActionButton, {
            props: { title: "Supprimer", disabled: true },
        });

        expect(wrapper.attributes("disabled")).toBeDefined();
    });

    it("takes an icon beside the words", () => {
        const wrapper = mount(AppActionButton, {
            props: { title: "Modifier" },
            slots: { icon: '<svg data-test="glyph" />' },
        });

        expect(wrapper.find('[data-test="glyph"]').exists()).toBe(true);
    });
});
