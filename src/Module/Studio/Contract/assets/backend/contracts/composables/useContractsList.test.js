import { describe, expect, it, vi } from "vitest";
import { useContractsList } from "./useContractsList.js";

vi.mock("vue-i18n", () => ({
    useI18n: () => ({ t: (key) => key }),
}));

vi.mock("vue-sonner", () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

vi.mock("@/shared/composables/http/backend/useRequest.js", () => ({
    useRequest: () => ({ request: vi.fn() }),
}));

const AMENDMENT_DRAFT = {
    id: 12,
    reference: null,
    customerId: 3,
    customerName: "Roux Photographie",
    locale: "fr",
    amountCents: 49000,
    amountCurrency: "EUR",
    effectiveDate: "2026-10-27",
    customFields: {},
    body: { templateId: 5 },
    annex: null,
    amends: { id: 7, reference: "CTR-2026-0002", rank: null },
};

function list() {
    return useContractsList({
        contracts: [AMENDMENT_DRAFT],
        amendable: [],
        locales: [{ code: "fr" }],
        createPath: "/create",
        updatePath: "/__id__/update",
    });
}

describe("useContractsList", () => {
    /**
     * Editing the draft of an amendment keeps the contract it amends.
     *
     * The edit form was built without the parent, the manager read the
     * missing id as « not an amendment », and saving a corrected amount turned
     * the amendment into a contract of its own.
     */
    it("keeps the parent when an amendment draft is edited", () => {
        const contracts = list();

        contracts.openEdit(AMENDMENT_DRAFT);

        expect(contracts.editForm.value.amendsId).toBe(7);
    });

    it("sends no parent for a contract that amends nothing", () => {
        const contracts = list();

        contracts.openEdit({ ...AMENDMENT_DRAFT, amends: null });

        expect(contracts.editForm.value.amendsId).toBeNull();
    });
});
