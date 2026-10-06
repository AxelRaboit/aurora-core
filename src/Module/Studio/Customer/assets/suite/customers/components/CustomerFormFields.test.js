import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import CustomerFormFields from "./CustomerFormFields.vue";
import { emptyCustomerForm } from "../composables/customerFormModel.js";

const i18n = createTestI18n();

function mountFields(modelValue = emptyCustomerForm(), errors = {}) {
    return mount(CustomerFormFields, {
        global: { plugins: [i18n], stubs: { teleport: true } },
        props: {
            modelValue,
            errors,
            currencies: [{ value: "EUR", symbol: "€" }],
        },
    });
}

function lastEmitted(wrapper) {
    const events = wrapper.emitted("update:modelValue");

    return events[events.length - 1][0];
}

/**
 * Le formulaire de toute la fiche.
 *
 * Ce qui se casse en silence ici : un champ qui disparaît du composant, et
 * qui ne se saisit alors plus nulle part, puisque la page du client est le
 * seul endroit qui écrit la fiche.
 */
describe("CustomerFormFields", () => {
    it("carries the fields the space tab used to own", () => {
        const wrapper = mountFields();
        const text = wrapper.text();

        for (const key of [
            "customers.siren",
            "customers.landline",
            "customers.links",
            "customers.notes",
            "customers.siret",
            "customers.trade_register",
        ]) {
            expect(text).toContain(key);
        }
    });

    it("no longer offers a user account", () => {
        expect(mountFields().text()).not.toContain("customers.account");
    });

    it("adds an empty link row, and removes the one asked for", async () => {
        const wrapper = mountFields({
            ...emptyCustomerForm(),
            links: [
                { label: "Site", url: "https://a.example.com" },
                { label: "Blog", url: "https://b.example.com" },
            ],
        });

        expect(wrapper.findAll("[data-customer-link]")).toHaveLength(2);

        const add = wrapper
            .findAll("button")
            .find((button) => button.text().includes("customers.link_add"));
        await add.trigger("click");
        expect(lastEmitted(wrapper).links).toHaveLength(3);
        expect(lastEmitted(wrapper).links[2]).toEqual({ label: "", url: "" });

        const remove = wrapper
            .findAll("[data-customer-link]")[0]
            .find("button");
        await remove.trigger("click");
        expect(lastEmitted(wrapper).links).toEqual([
            { label: "Blog", url: "https://b.example.com" },
        ]);
    });

    it("edits one link without touching the others", async () => {
        const wrapper = mountFields({
            ...emptyCustomerForm(),
            links: [
                { label: "Site", url: "https://a.example.com" },
                { label: "Blog", url: "https://b.example.com" },
            ],
        });

        const urlInput = wrapper
            .findAll("[data-customer-link]")[1]
            .findAll("input")[1];
        await urlInput.setValue("https://blog.example.com");

        expect(lastEmitted(wrapper).links).toEqual([
            { label: "Site", url: "https://a.example.com" },
            { label: "Blog", url: "https://blog.example.com" },
        ]);
    });

    it("pins a link error under its own row", () => {
        const wrapper = mountFields(
            { ...emptyCustomerForm(), links: [{ label: "Site", url: "" }] },
            { "links[0].url": "Ce lien n'a pas d'adresse." },
        );

        expect(wrapper.find("[data-customer-link]").text()).toContain(
            "Ce lien n'a pas d'adresse.",
        );
    });
});
