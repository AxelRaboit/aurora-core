import { beforeEach, describe, expect, it, vi } from "vitest";
import { useContractFlow } from "./useContractFlow.js";

let granted = [];

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

vi.mock("@/shared/composables/usePrivileges.js", () => ({
    usePrivileges: () => ({ can: (privilege) => granted.includes(privilege) }),
}));

const ALL = [
    "studio.contracts.edit",
    "studio.contracts.send",
    "studio.contracts.countersign",
    "studio.contracts.create",
    "studio.contracts.delete",
];

function contract(status, extra = {}) {
    return { status, hasPdf: false, isDeletable: false, amends: null, termination: null, ...extra };
}

const keys = (actions) => actions.map((each) => each.key);

beforeEach(() => {
    granted = [...ALL];
});

/**
 * The list and the contract's screen used to build their menus apart, and
 * disagreed: « Envoyer au client » on a concluded, refused or expired
 * contract in one, nothing at all before the countersignature in the other.
 */
describe("useContractFlow", () => {
    it("never offers to send a contract somebody has signed", () => {
        const { flowOf } = useContractFlow();

        for (const status of ["signed_by_customer", "countersigned"]) {
            const { next, others } = flowOf(contract(status));
            const all = [next?.key, ...keys(others)];

            expect(all).not.toContain("send");
            expect(all).not.toContain("resend");
            expect(all).not.toContain("remind");
        }
    });

    it("names the step that comes next for each status", () => {
        const { flowOf } = useContractFlow();
        const next = (status, extra) => flowOf(contract(status, extra)).next?.key ?? null;

        expect(next("draft")).toBe("freeze");
        expect(next("sealed")).toBe("send");
        expect(next("sent")).toBe("remind");
        expect(next("opened")).toBe("remind");
        expect(next("refused")).toBe("resend");
        expect(next("expired")).toBe("resend");
        expect(next("signed_by_customer")).toBe("countersign");
        expect(next("countersigned", { hasPdf: true })).toBe("download");
        expect(next("cancelled")).toBeNull();
    });

    it("offers to cancel only while nobody has signed", () => {
        const { flowOf } = useContractFlow();
        const offers = (status) => keys(flowOf(contract(status)).others).includes("cancel");

        expect(offers("sealed")).toBe(true);
        expect(offers("refused")).toBe(true);
        expect(offers("sent")).toBe(false);
        expect(offers("signed_by_customer")).toBe(false);
        expect(offers("countersigned")).toBe(false);
    });

    it("amends and terminates a running contract, not an amendment or an ended one", () => {
        const { flowOf } = useContractFlow();
        const others = (extra) => keys(flowOf(contract("countersigned", extra)).others);

        expect(others({})).toEqual(expect.arrayContaining(["amend", "terminate"]));
        expect(others({ amends: { id: 1 } })).not.toContain("amend");
        expect(others({ termination: { isEffective: false } })).toContain("amend");
        expect(others({ termination: { isEffective: false } })).not.toContain("terminate");
        expect(others({ termination: { isEffective: true } })).not.toContain("amend");
    });

    it("deletes only what the server says it would delete", () => {
        const { flowOf } = useContractFlow();

        expect(keys(flowOf(contract("countersigned")).others)).not.toContain("delete");
        expect(keys(flowOf(contract("countersigned", { isDeletable: true })).others)).toContain("delete");
    });

    it("leaves out what the reader may not do", () => {
        granted = ["studio.contracts.edit"];
        const { flowOf } = useContractFlow();

        expect(flowOf(contract("sealed")).next).toBeNull();
        expect(flowOf(contract("signed_by_customer")).next).toBeNull();
        expect(flowOf(contract("draft")).next?.key).toBe("freeze");
    });

    it("opens the contract first from a row, and has no preview on its own screen", () => {
        const { flowOf } = useContractFlow();

        expect(flowOf(contract("draft"), { list: true }).others[0].key).toBe("open");
        expect(keys(flowOf(contract("draft")).others)).not.toContain("preview");
    });
});
