import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useUsersActions } from "./useUsersActions.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({ request: vi.fn() }),
}));

function actionsFor(props) {
    const wrapper = mount({
        setup() {
            return { actions: useUsersActions(props, vi.fn()) };
        },
        template: "<i />",
    });

    return wrapper.vm.actions;
}

/**
 * The same address can hold a suite account and a public site account. Both
 * used to read "Vous", and the second one could not be acted on: the signed-in
 * account is the one with the signed-in id, and only that one.
 */
describe("useUsersActions", () => {
    const signedIn = { id: 1, email: "dev@aurora.app", rolePriority: 100 };
    const publicSite = { id: 2, email: "dev@aurora.app", rolePriority: 0 };
    const actions = actionsFor({ currentUserId: 1, currentUserPriority: 100 });

    it("marks only the signed-in account as current", () => {
        expect(actions.isCurrent(signedIn)).toBe(true);
        expect(actions.isCurrent(publicSite)).toBe(false);
    });

    it("lets an administrator act on the public site account at the same address", () => {
        expect(actions.canActOn(publicSite)).toBe(true);
        expect(actions.canActOn(signedIn)).toBe(false);
    });
});
