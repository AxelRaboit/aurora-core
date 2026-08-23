import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import AppRowActions from "./AppRowActions.vue";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

function mountActions(actions, props = {}) {
    return mount(AppRowActions, {
        props: { actions, label: "Jean", ...props },
        global: {
            stubs: {
                // AppModal teleports and traps focus; neither is what this
                // component is responsible for. Rendering its slot inline is
                // enough to assert what it was handed.
                AppModal: {
                    props: ["show"],
                    template:
                        '<div v-if="show" data-test="sheet"><slot /></div>',
                },
            },
        },
    });
}

const DELETE = { key: "delete", title: "Supprimer", color: "rose" };

describe("AppRowActions", () => {
    it("shows nothing until the trigger is pressed", async () => {
        const wrapper = mountActions([DELETE]);

        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(false);

        await wrapper.find("button").trigger("click");

        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(true);
    });

    it("lists every action it was handed, in the order it was handed them", async () => {
        const wrapper = mountActions([
            { key: "view", title: "Voir" },
            { key: "edit", title: "Modifier" },
            DELETE,
        ]);

        await wrapper.find("button").trigger("click");

        const rows = wrapper.findAll(
            '[data-test="sheet"] button, [data-test="sheet"] a',
        );
        expect(rows.map((r) => r.text())).toEqual([
            "Voir",
            "Modifier",
            "Supprimer",
        ]);
    });

    it("calls the action it was given, and closes on the way out", async () => {
        const onSelect = vi.fn();
        const wrapper = mountActions([{ ...DELETE, onSelect }]);

        await wrapper.find("button").trigger("click");
        await wrapper.find('[data-test="sheet"] button').trigger("click");

        expect(onSelect).toHaveBeenCalledOnce();
        // Closed before the caller's own modal opens: two stacked overlays is
        // one too many.
        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(false);
    });

    // Some actions are navigations - impersonating a user, opening a file -
    // and have to stay openable in a new tab.
    it("renders a link for an action that navigates", async () => {
        const wrapper = mountActions([
            { key: "open", title: "Ouvrir", href: "/documents/1" },
        ]);

        await wrapper.find("button").trigger("click");
        const link = wrapper.find('[data-test="sheet"] a');

        expect(link.exists()).toBe(true);
        expect(link.attributes("href")).toBe("/documents/1");
    });

    it("does nothing for a disabled action, and stays open", async () => {
        const onSelect = vi.fn();
        const wrapper = mountActions([{ ...DELETE, onSelect, disabled: true }]);

        await wrapper.find("button").trigger("click");
        await wrapper.find('[data-test="sheet"] button').trigger("click");

        expect(onSelect).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(true);
    });
});
