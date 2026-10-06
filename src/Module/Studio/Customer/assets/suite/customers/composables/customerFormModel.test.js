import { describe, it, expect } from "vitest";
import { ref } from "vue";
import {
    centsToInput,
    customerFormFrom,
    customerFormRules,
    emptyCustomerForm,
} from "./customerFormModel.js";

const t = (key) => key;

describe("customerFormModel", () => {
    it("carries every field of the record, the ones a space used to own included", () => {
        // Le SIREN, le fixe, les liens et les notes ne se saisissaient que
        // depuis l'onglet d'un espace : la fiche de la page les porte tous.
        const form = emptyCustomerForm();

        for (const field of [
            "siren",
            "landline",
            "links",
            "informationNotes",
            "shareCapital",
            "tradeRegister",
            "vatNumber",
        ]) {
            expect(form).toHaveProperty(field);
        }
        expect(form.status).toBe("prospect");
    });

    it("no longer has an account field", () => {
        expect(emptyCustomerForm()).not.toHaveProperty("userId");
        expect(customerFormFrom({ userId: 3 })).not.toHaveProperty("userId");
    });

    it("fills the form from a stored record, and copies the links rather than sharing them", () => {
        const links = [
            { label: "Site web", url: "https://atelier.example.com" },
        ];
        const form = customerFormFrom({
            legalName: "Atelier Temoin",
            siren: "112817044",
            landline: "01 23 45 67 89",
            links,
            informationNotes: "Visible par le client",
            shareCapitalCents: 1_000_050,
            status: "client",
        });

        expect(form.siren).toBe("112817044");
        expect(form.landline).toBe("01 23 45 67 89");
        expect(form.informationNotes).toBe("Visible par le client");
        expect(form.shareCapital).toBe("10000.50");
        expect(form.links).toEqual(links);
        expect(form.links).not.toBe(links);
        expect(form.links[0]).not.toBe(links[0]);
    });

    it("shows a round capital without decimals", () => {
        expect(centsToInput(1_000_000)).toBe("10000");
        expect(centsToInput(null)).toBe("");
    });

    it("asks a client for its contractual email, and a prospect for nothing but its name", () => {
        const form = ref({
            ...emptyCustomerForm(),
            legalName: "Atelier",
            status: "client",
            contractualEmail: "",
        });

        expect(customerFormRules(t, form).contractualEmail()).toBe(
            "suite.studio.customers.errors.contractual_email_required",
        );

        form.value.status = "prospect";
        expect(customerFormRules(t, form).contractualEmail()).toBeNull();

        form.value.legalName = "";
        expect(customerFormRules(t, form).legalName()).toBe(
            "suite.studio.customers.errors.legal_name_required",
        );
    });
});
