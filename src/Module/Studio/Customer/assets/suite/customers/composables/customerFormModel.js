import { required } from "@/shared/utils/validation/validators.js";

/**
 * La fiche d'un client, sous la forme que le formulaire édite.
 *
 * **Une seule forme pour créer et pour modifier**, et désormais pour toute la
 * fiche : la fenêtre de création de la liste et la page du client remplissent
 * les mêmes champs, avec le même composant. Il y avait deux formulaires qui ne
 * portaient pas les mêmes, et le SIREN ne se saisissait que depuis un espace.
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
        // Ce qu'est une fiche au moment où on la crée.
        status: "prospect",
    };
}

/**
 * Un montant enregistré est en centimes, un montant tapé en unités. Les
 * décimales n'apparaissent que quand il y en a : un capital de 10 000 € ne se
 * relit pas « 10 000,00 » dans un champ qu'on s'apprête à retaper.
 */
export function centsToInput(cents) {
    if (cents === null || cents === undefined) return "";

    const units = cents / 100;

    return Number.isInteger(units) ? String(units) : units.toFixed(2);
}

/** La forme du formulaire, depuis ce que le serveur rend d'une fiche. */
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
        // Copiés ligne par ligne : partager le tableau reçu ferait bouger ce
        // que la page affiche de la fiche enregistrée pendant la frappe.
        links: (customer?.links ?? []).map((link) => ({
            label: link.label ?? "",
            url: link.url ?? "",
        })),
        informationNotes: customer?.informationNotes ?? "",
        status: customer?.status ?? "prospect",
    };
}

/**
 * Ce que le navigateur vérifie avant d'envoyer : la raison sociale, et
 * l'email contractuel d'un client. Le serveur tient les mêmes règles, et les
 * autres (SIRET, SIREN, liens) restent les siennes.
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
        // Exigée d'un client, facultative d'un prospect : c'est la seule chose
        // que le statut impose, et le serveur la tient aussi.
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
