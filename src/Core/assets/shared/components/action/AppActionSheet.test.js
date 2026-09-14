import { describe, it, expect, vi } from "vitest";
import { h } from "vue";
import { mount } from "@vue/test-utils";
import AppActionSheet from "./AppActionSheet.vue";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

function mountSheet(actions, props = {}) {
    return mount(AppActionSheet, {
        props: { actions, label: "Jean", ...props },
        slots: {
            // The trigger is the caller's; this stands in for whatever the two
            // façades press.
            trigger: ({ open }) =>
                h(
                    "button",
                    { "data-test": "trigger", onClick: open },
                    "Ouvrir",
                ),
        },
        global: {
            stubs: {
                // AppModal teleports and traps focus; neither is what this
                // component is responsible for. Rendering its slot inline is
                // enough to assert what it was handed.
                AppModal: {
                    props: ["show", "title"],
                    template: `
                        <div v-if="show" data-test="sheet">
                            <p data-test="sheet-title">{{ title }}</p>
                            <slot />
                        </div>
                    `,
                },
            },
        },
    });
}

const DELETE = { key: "delete", title: "Supprimer", color: "rose" };

const rowsOf = (wrapper) =>
    wrapper.findAll('[data-test="sheet"] button, [data-test="sheet"] a');

describe("AppActionSheet", () => {
    it("shows nothing until the trigger opens it", async () => {
        const wrapper = mountSheet([DELETE]);

        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(false);

        await wrapper.find('[data-test="trigger"]').trigger("click");

        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(true);
    });

    it("lists every action it was handed, in the order it was handed them", async () => {
        const wrapper = mountSheet([
            { key: "view", title: "Voir" },
            { key: "edit", title: "Modifier" },
            DELETE,
        ]);

        await wrapper.find('[data-test="trigger"]').trigger("click");

        expect(rowsOf(wrapper).map((r) => r.text())).toEqual([
            "Voir",
            "Modifier",
            "Supprimer",
        ]);
    });

    it("calls the action it was given, and closes on the way out", async () => {
        const onSelect = vi.fn();
        const wrapper = mountSheet([{ ...DELETE, onSelect }]);

        await wrapper.find('[data-test="trigger"]').trigger("click");
        await rowsOf(wrapper)[0].trigger("click");

        expect(onSelect).toHaveBeenCalledOnce();
        // Closed before the caller's own modal opens: two stacked overlays is
        // one too many.
        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(false);
    });

    // Some actions are navigations - impersonating a user, opening a file -
    // and have to stay openable in a new tab.
    it("renders a link for an action that navigates", async () => {
        const wrapper = mountSheet([
            { key: "open", title: "Ouvrir le document", href: "/documents/1" },
        ]);

        await wrapper.find('[data-test="trigger"]').trigger("click");
        const link = wrapper.find('[data-test="sheet"] a');

        expect(link.exists()).toBe(true);
        expect(link.attributes("href")).toBe("/documents/1");
    });

    it("does nothing for a disabled action, and stays open", async () => {
        const onSelect = vi.fn();
        const wrapper = mountSheet([{ ...DELETE, onSelect, disabled: true }]);

        await wrapper.find('[data-test="trigger"]').trigger("click");
        await rowsOf(wrapper)[0].trigger("click");

        expect(onSelect).not.toHaveBeenCalled();
        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(true);
    });

    /**
     * A reader who reopens the sheet while a save is in flight should see it is
     * in flight, and should not be able to fire it a second time.
     */
    it("does nothing for an action already running, and shows it running", async () => {
        const onSelect = vi.fn();
        const wrapper = mountSheet([
            { key: "save", title: "Enregistrer", onSelect, loading: true },
        ]);

        await wrapper.find('[data-test="trigger"]').trigger("click");
        const row = rowsOf(wrapper)[0];
        await row.trigger("click");

        expect(onSelect).not.toHaveBeenCalled();
        expect(row.attributes("disabled")).toBeDefined();
        expect(row.find(".animate-spin").exists()).toBe(true);
    });

    it("names what is being acted on, and falls back to a plain title", async () => {
        const named = mountSheet([DELETE]);
        const plain = mountSheet([DELETE], { label: "" });

        await named.find('[data-test="trigger"]').trigger("click");
        await plain.find('[data-test="trigger"]').trigger("click");

        expect(named.get('[data-test="sheet-title"]').text()).toBe(
            "shared.actions.title",
        );
        expect(plain.get('[data-test="sheet-title"]').text()).toBe(
            "shared.actions.plain_title",
        );
    });
});
