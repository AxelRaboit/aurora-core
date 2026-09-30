/**
 * The edit form of a contract, filled from what the server sent.
 *
 * One builder for the list and for the contract's own screen: built twice, the
 * two drifted, and the list's copy forgot the parent of an amendment, which
 * saving then detached.
 *
 * @param {object} contract as the server serializes it
 * @returns {object} the form's fields
 */
export function formFromContract(contract) {
    return {
        amendsId: contract.amends?.id ?? null,
        customerId: String(contract.customerId ?? ""),
        bodyTemplateId: String(contract.body?.templateId ?? ""),
        annexTemplateId: String(contract.annex?.templateId ?? ""),
        locale: contract.locale,
        amount:
            contract.amountCents === null || contract.amountCents === undefined
                ? ""
                : String(contract.amountCents / 100),
        amountCurrency: contract.amountCurrency ?? "EUR",
        effectiveDate: contract.effectiveDate ?? "",
        customFields: { ...(contract.customFields ?? {}) },
    };
}
