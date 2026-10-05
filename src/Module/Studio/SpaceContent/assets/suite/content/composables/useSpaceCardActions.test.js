import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useSpaceCardActions } from "./useSpaceCardActions.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

function actionsFor({ editable }) {
    const open = vi.fn();
    const confirmDelete = vi.fn();

    const wrapper = mount({
        setup() {
            return {
                build: useSpaceCardActions({
                    can: (permission) =>
                        "studio.spaces.edit" === permission ? editable : true,
                    open,
                    confirmDelete,
                }),
            };
        },
        template: "<i />",
    });

    return { actionsFor: wrapper.vm.build, open, confirmDelete };
}

const CARD = { id: 7, title: "Portrait de l'équipe" };

describe("useSpaceCardActions", () => {
    /**
     * The regression this file exists for.
     *
     * The menu used to hold edit and delete, both behind `studio.spaces.edit`,
     * so for a reader who may see a space but not change it the menu was empty
     * - and a board card was not clickable either. They saw a title and a date
     * and could never reach the text, the thread or the files, on a screen that
     * exists to be read.
     */
    it("offers a way in to somebody who may not edit", () => {
        const { actionsFor: build, open } = actionsFor({ editable: false });
        const actions = build(CARD);

        expect(actions.map((action) => action.key)).toEqual(["view"]);

        actions[0].onSelect();
        expect(open).toHaveBeenCalledWith(CARD);
    });

    it("offers editing and deleting to somebody who may", () => {
        const {
            actionsFor: build,
            open,
            confirmDelete,
        } = actionsFor({
            editable: true,
        });
        const actions = build(CARD);

        expect(actions.map((action) => action.key)).toEqual(["edit", "delete"]);

        actions[0].onSelect();
        expect(open).toHaveBeenCalledWith(CARD);

        actions[1].onSelect();
        expect(confirmDelete).toHaveBeenCalledWith(CARD);
    });

    /**
     * Not both. Two entries opening the same panel, one of which happens to
     * disable its fields, is a menu that makes the reader guess.
     */
    it("never offers view and edit at once", () => {
        for (const editable of [true, false]) {
            const keys = actionsFor({ editable })
                .actionsFor(CARD)
                .map((action) => action.key);

            expect(keys.includes("view") && keys.includes("edit")).toBe(false);
        }
    });

    it("puts the destructive one last", () => {
        const keys = actionsFor({ editable: true })
            .actionsFor(CARD)
            .map((action) => action.key);

        expect(keys[keys.length - 1]).toBe("delete");
    });
});
