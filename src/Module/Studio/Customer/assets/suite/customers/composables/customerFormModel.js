import { required } from "@/shared/utils/validation/validators.js";

/**
 * A customer's sheet, in the shape the form edits.
 *
 * **A single shape to create and to edit**, and now for the whole sheet: the
 * list's creation dialog and the customer's page fill in the same fields,
 * with the same component. There used to be two forms that did not carry the
 * same ones, and the SIREN could only be entered from a space.
 */
export function emptyCustomerForm() {
    return {
        legalName: "",
        legalForm: "",
        shareCapital: "",
        shareCapitalCurrency: "EUR",
        registeredOffice: "",
        siret: "",
        siren: "",
        tradeRegister: "",
        vatNumber: "",
        activitySector: "",
        representativeFirstName: "",
        representativeLastName: "",
        representativeRole: "",
        contractualEmail: "",
        phone: "",
        landline: "",
        links: [],
        informationNotes: "",
        // What a sheet is at the moment it is created.
        status: "prospect",
    };
}

/**
 * A stored amount is in cents, a typed amount in units. Decimals only appear
 * when there are some: a capital of 10 000 € does not read back as
 * "10 000,00" in a field about to be typed again.
 */
export function centsToInput(cents) {
    if (cents === null || cents === undefined) return "";

    const units = cents / 100;

    return Number.isInteger(units) ? String(units) : units.toFixed(2);
}

/** The form's shape, from what the server returns for a sheet. */
export function customerFormFrom(customer) {
    return {
        legalName: customer?.legalName ?? "",
        legalForm: customer?.legalForm ?? "",
        shareCapital: centsToInput(customer?.shareCapitalCents),
        shareCapitalCurrency: customer?.shareCapitalCurrency ?? "EUR",
        registeredOffice: customer?.registeredOffice ?? "",
        siret: customer?.siret ?? "",
        siren: customer?.siren ?? "",
        tradeRegister: customer?.tradeRegister ?? "",
        vatNumber: customer?.vatNumber ?? "",
        activitySector: customer?.activitySector ?? "",
        representativeFirstName: customer?.representativeFirstName ?? "",
        representativeLastName: customer?.representativeLastName ?? "",
        representativeRole: customer?.representativeRole ?? "",
        contractualEmail: customer?.contractualEmail ?? "",
        phone: customer?.phone ?? "",
        landline: customer?.landline ?? "",
        // Copied row by row: sharing the received array would move what the
        // page shows of the saved sheet while typing.
        links: (customer?.links ?? []).map((link) => ({
            label: link.label ?? "",
            url: link.url ?? "",
        })),
        informationNotes: customer?.informationNotes ?? "",
        status: customer?.status ?? "prospect",
    };
}

/**
 * What the browser checks before sending: the company name, and a
 * customer's contractual email. The server holds the same rules, and the
 * others (SIRET, SIREN, links) remain its own.
 *
 * @param {(key: string) => string} t
 * @param {import("vue").Ref<object>} form
 */
export function customerFormRules(t, form) {
    return {
        legalName: () =>
            required(t("suite.studio.customers.errors.legal_name_required"))(
                form.value.legalName,
            ),
        // Required for a customer, optional for a prospect: it is the only
        // thing the status requires, and the server holds it too.
        contractualEmail: () =>
            "prospect" === form.value.status
                ? null
                : required(
                      t(
                          "suite.studio.customers.errors.contractual_email_required",
                      ),
                  )(form.value.contractualEmail),
    };
}
