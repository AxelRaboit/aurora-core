import { describe, expect, it, vi } from "vitest";
// Relative rather than aliased, like its neighbour: the Studio module keeps its
// assets under its sub-domains, so there is no `@studio` alias to import through.
import { useContractActions } from "./useContractActions.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

let granted = [];

vi.mock("@/shared/composables/usePrivileges.js", () => ({
    usePrivileges: () => ({ can: (privilege) => granted.includes(privilege) }),
}));

const DRAFT = { id: 7, customerName: "Boulangerie Durand" };
const SEALED = { id: 9, reference: "CTR-2026-0001", link: null };

const handlers = () => ({
    preview: vi.fn(),
    edit: vi.fn(),
    freeze: vi.fn(),
    remove: vi.fn(),
    send: vi.fn(),
    revoke: vi.fn(),
    documentPath: (contract) => `/backend/studio/contracts/${contract.id}`,
    exportPath: (contract) => `/backend/studio/contracts/${contract.id}/export`,
});

function keyed(actions, key) {
    return actions.find((action) => action.key === key);
}

/**
 * The export is the one entry both halves of the page share, and the only one
 * that asks nothing of the caller but an address. These cover the two ways that
 * could quietly break: it disappearing from one of the two sets, and it
 * becoming a click handler - which would take the download out of a plain link
 * and stop it opening in a new tab.
 */
describe("useContractActions", () => {
    it("offers the export on a draft, as a link", () => {
        granted = [];
        const { draftActions } = useContractActions();

        const action = keyed(draftActions(DRAFT, handlers()), "export");

        expect(action).toBeDefined();
        expect(action.href).toBe("/backend/studio/contracts/7/export");
        expect(action.onSelect).toBeUndefined();
    });

    it("offers it on a sealed contract too", () => {
        granted = [];
        const { sealedActions } = useContractActions();

        const action = keyed(sealedActions(SEALED, handlers()), "export");

        expect(action).toBeDefined();
        expect(action.href).toBe("/backend/studio/contracts/9/export");
    });

    /**
     * Reading a contract is not changing one. Somebody who may only see the
     * list still needs the document it is a list of.
     */
    it("offers it to somebody with no write privilege at all", () => {
        granted = [];
        const { draftActions } = useContractActions();

        const actions = draftActions(DRAFT, handlers());

        expect(actions.map((action) => action.key)).toEqual([
            "preview",
            "export",
        ]);
    });
});
