import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useUserActions } from "./useUserActions.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

/**
 * The rules were only provable by mounting the component and counting buttons
 * before they were pulled out. They matter one by one: getting a condition
 * wrong offers a destructive action to somebody who should not have it.
 */
function actionsFor(overrides = {}) {
    const props = {
        user: {
            id: 1,
            name: "Jean",
            email: "jean@exemple.com",
            type: "suite",
            status: "active",
            isDev: false,
        },
        isDev: false,
        canAct: false,
        canEdit: false,
        hasPrivileges: false,
        canManageDisabledModules: false,
        impersonatePath: "/impersonate?email=__email__",
        impersonateFrontPath: "",
        ...overrides,
    };

    const wrapper = mount({
        setup() {
            return { actions: useUserActions(props) };
        },
        template: "<i />",
    });

    return wrapper.vm.actions.map((action) => action.key);
}

describe("what one operator may do to one user", () => {
    // Reading a record is not acting on it, so it survives every restriction.
    it("always offers to look", () => {
        expect(actionsFor()).toEqual(["view"]);
    });

    it("offers the whole set to someone who may act", () => {
        expect(actionsFor({ canAct: true })).toEqual([
            "view",
            "edit",
            "toggle-disabled",
            "delete",
        ]);
    });

    it("lets an editor edit without letting them destroy", () => {
        const keys = actionsFor({ canEdit: true });

        expect(keys).toContain("edit");
        expect(keys).not.toContain("delete");
        expect(keys).not.toContain("toggle-disabled");
    });

    it("offers to re-invite only an account that was invited", () => {
        expect(
            actionsFor({ canAct: true, user: user({ status: "invited" }) }),
        ).toContain("resend");
        expect(actionsFor({ canAct: true })).not.toContain("resend");
    });

    /**
     * Two routes, never both: a suite account is impersonated through the
     * admin, a frontend one through the public site.
     */
    it("picks the impersonation route the account type calls for", () => {
        const suite = actionsFor({ isDev: true, canAct: true });
        const frontend = actionsFor({
            isDev: true,
            canAct: true,
            user: user({ type: "frontend" }),
            impersonateFrontPath: "/front/impersonate/__id__",
        });

        expect(suite).toContain("impersonate");
        expect(suite).not.toContain("impersonate-front");
        expect(frontend).toContain("impersonate-front");
        expect(frontend).not.toContain("impersonate");
    });

    it("keeps impersonation for developers", () => {
        expect(actionsFor({ canAct: true })).not.toContain("impersonate");
    });

    // A developer's own rights are not editable from here, whoever is asking.
    it("refuses to edit a developer's privileges or modules", () => {
        const keys = actionsFor({
            isDev: true,
            canAct: true,
            hasPrivileges: true,
            canManageDisabledModules: true,
            user: user({ isDev: true }),
        });

        expect(keys).not.toContain("privileges");
        expect(keys).not.toContain("modules");
    });

    it("says enable for a disabled account and disable for a live one", () => {
        // `invitedAt` set: this account was contacted, then disabled. Opening
        // it again gives its access back, without sending anything.
        const disabled = useTitles({
            canAct: true,
            user: user({
                status: "disabled",
                invitedAt: "2026-01-01T10:00:00Z",
            }),
        });
        const live = useTitles({ canAct: true });

        expect(disabled).toContain("suite.users.enable");
        expect(live).toContain("suite.users.disable");
    });

    /**
     * A pre-provisioned account: created disabled, never contacted, so
     * `invitedAt` null. Opening it sends its invitation - it is not the same
     * action, and saying "réactiver" would suggest access is being given back
     * to someone who never had it, while hiding that an email goes out.
     */
    it("says enable and invite for an account nobody was ever told about", () => {
        const titles = useTitles({
            canAct: true,
            user: user({ status: "disabled", invitedAt: null }),
        });

        expect(titles).toContain("suite.users.enable_and_invite");
        expect(titles).not.toContain("suite.users.enable");
    });

    // Destroying is read last on purpose, and nothing is offered after it.
    it("puts the destructive action at the end", () => {
        const keys = actionsFor({
            canAct: true,
            isDev: true,
            hasPrivileges: true,
        });

        expect(keys[keys.length - 1]).toBe("delete");
    });

    it("builds the impersonation link from the account's own address", () => {
        const wrapper = mount({
            setup() {
                return {
                    actions: useUserActions({
                        user: user({}),
                        isDev: true,
                        canAct: true,
                        canEdit: false,
                        hasPrivileges: false,
                        canManageDisabledModules: false,
                        impersonatePath: "/impersonate?email=__email__",
                        impersonateFrontPath: "",
                    }),
                };
            },
            template: "<i />",
        });

        const impersonate = wrapper.vm.actions.find(
            (a) => "impersonate" === a.key,
        );

        // Encoded by `buildPath`, which is the point: an address goes into a
        // query string, where `@` and anything else it may hold has to be safe.
        expect(impersonate.href).toBe("/impersonate?email=jean%40exemple.com");
        expect(impersonate.emitName).toBeUndefined();
    });
});

function user(overrides) {
    return {
        id: 1,
        name: "Jean",
        email: "jean@exemple.com",
        type: "suite",
        status: "active",
        isDev: false,
        ...overrides,
    };
}

function useTitles(overrides) {
    const props = {
        user: user({}),
        isDev: false,
        canAct: false,
        canEdit: false,
        hasPrivileges: false,
        canManageDisabledModules: false,
        impersonatePath: "",
        impersonateFrontPath: "",
        ...overrides,
    };

    const wrapper = mount({
        setup() {
            return { actions: useUserActions(props) };
        },
        template: "<i />",
    });

    return wrapper.vm.actions.map((action) => action.title);
}
