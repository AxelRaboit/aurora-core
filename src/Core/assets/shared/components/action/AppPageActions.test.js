import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import AppPageActions from "./AppPageActions.vue";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

function mountActions(actions, props = {}) {
    return mount(AppPageActions, {
        props: { actions, ...props },
        global: {
            stubs: {
                AppModal: {
                    props: ["show"],
                    template:
                        '<div v-if="show" data-test="sheet"><slot /></div>',
                },
            },
        },
    });
}

const PRINT = { key: "print", title: "Imprimer" };

// The trigger slot renders before the sheet, so it is the first button on the
// page whether the sheet is open or not.
const trigger = (wrapper) => wrapper.findAll("button")[0];

describe("AppPageActions", () => {
    /**
     * The reason this exists apart from AppRowActions: a table column is headed
     * "Actions", so three dots under it are legible. A page header has no such
     * heading, and a lone glyph among labelled buttons reads as a mystery.
     */
    it("says the word rather than showing three dots", () => {
        const wrapper = mountActions([PRINT]);

        expect(trigger(wrapper).text()).toContain("shared.actions.plain_title");
    });

    it("opens the sheet and runs what was chosen", async () => {
        const onSelect = vi.fn();
        const wrapper = mountActions([{ ...PRINT, onSelect }]);

        expect(wrapper.find('[data-test="sheet"]').exists()).toBe(false);

        await trigger(wrapper).trigger("click");
        await wrapper.find('[data-test="sheet"] button').trigger("click");

        expect(onSelect).toHaveBeenCalledOnce();
    });

    /**
     * The button that started the action is inside a sheet that closed itself,
     * so the trigger carries the spinner in its place.
     */
    it("shows the trigger working while an action it started runs", async () => {
        const wrapper = mountActions([PRINT], { busy: true });

        expect(trigger(wrapper).find(".animate-spin").exists()).toBe(true);
        expect(trigger(wrapper).attributes("disabled")).toBeDefined();
    });

    it("sits at the weight the header asks for", () => {
        const wrapper = mountActions([PRINT], { variant: "ghost", size: "sm" });

        expect(trigger(wrapper).classes()).toContain("sm:bg-transparent");
        expect(trigger(wrapper).classes()).toContain("text-xs");
    });

    // The sheet has two roots: a `class` set on this component got lost. It
    // now lands on the row that holds the buttons.
    it("puts the classes it receives on the row of buttons", () => {
        const wrapper = mount(AppPageActions, {
            props: { actions: [PRINT] },
            attrs: { class: "w-full sm:w-auto" },
            global: { stubs: { AppModal: { template: "<div />" } } },
        });
        expect(wrapper.classes()).toEqual(
            expect.arrayContaining(["w-full", "sm:w-auto"]),
        );
    });

    /**
     * The create verb used to sit alone in the sheet: two clicks and a modal
     * for the most frequent gesture of a list.
     */
    describe("a primary action", () => {
        const CREATE = {
            key: "create",
            title: "Nouvelle publication",
            primary: true,
        };

        it("stands beside the sheet as the page's main button", async () => {
            const onSelect = vi.fn();
            const wrapper = mountActions([PRINT, { ...CREATE, onSelect }]);
            const buttons = wrapper.findAll("button");

            expect(buttons).toHaveLength(2);
            expect(buttons[1].text()).toContain("Nouvelle publication");

            await buttons[1].trigger("click");
            expect(onSelect).toHaveBeenCalledOnce();
        });

        it("leaves no « Actions » button when nothing else is offered", () => {
            const wrapper = mountActions([CREATE]);
            const buttons = wrapper.findAll("button");

            expect(buttons).toHaveLength(1);
            expect(buttons[0].text()).toContain("Nouvelle publication");
            expect(wrapper.text()).not.toContain("shared.actions.plain_title");
        });

        it("is not offered a second time in the sheet", async () => {
            const wrapper = mountActions([PRINT, CREATE]);

            await trigger(wrapper).trigger("click");

            expect(wrapper.find('[data-test="sheet"]').text()).not.toContain(
                "Nouvelle publication",
            );
        });
    });
});
