import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useSpaceRowActions } from "./useSpaceRowActions.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

const SPACE = { id: 7, name: "Atelier Dupont - Réseaux sociaux" };

function actions(granted) {
    const wrapper = mount({
        setup() {
            return {
                build: useSpaceRowActions({
                    can: (permission) => granted.includes(permission),
                    boardHref: (space) => `/workspace/${space.id}`,
                    openEdit: vi.fn(),
                    confirmDelete: vi.fn(),
                }),
            };
        },
        template: "<i />",
    });

    return wrapper.vm.build(SPACE);
}

describe("useSpaceRowActions", () => {
    /**
     * The reason this list stopped using the shared edit/delete pair.
     *
     * A menu called Actions that lists everything one can do to a row, minus
     * the thing one actually does to it every day, answers the wrong question
     * - and for a reader who may not edit it held nothing at all.
     */
    it("opens the space first, whatever else the reader may do", () => {
        expect(actions([]).map((action) => action.key)).toEqual(["view"]);

        expect(
            actions(["studio.spaces.edit", "studio.spaces.delete"]).map(
                (action) => action.key,
            ),
        ).toEqual(["view", "edit", "delete"]);
    });

    it("makes opening a link rather than a handler", () => {
        // So a client's space opens in another tab, which is what somebody
        // does when comparing two of them.
        const [view] = actions([]);

        expect(view.href).toBe("/workspace/7");
        expect(view.onSelect).toBeUndefined();
    });

    it("keeps the destructive entry last", () => {
        const built = actions(["studio.spaces.edit", "studio.spaces.delete"]);

        expect(built.at(-1).key).toBe("delete");
    });
});
