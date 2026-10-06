import { describe, it, expect, vi, beforeEach } from "vitest";
import { ref } from "vue";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const granted = new Set();

vi.mock("@/shared/composables/usePrivileges.js", () => ({
    usePrivileges: () => ({
        can: (privilege) => granted.has(privilege),
        isDev: false,
        isAdmin: false,
    }),
}));
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({
        loading: ref(false),
        request: () => Promise.resolve({ success: true }),
    }),
}));

const { default: CustomerPageApp } = await import("./CustomerPageApp.vue");

const i18n = createTestI18n();

const CUSTOMER = {
    id: 12,
    legalName: "Atelier Temoin",
    status: "prospect",
    statusLabel: "suite.studio.customers.statuses.prospect",
    legalForm: "SARL",
    siret: "11281704400004",
    siren: "112817044",
    landline: "04 76 00 00 00",
    links: [{ label: "Site web", url: "https://atelier.example.com" }],
    informationNotes: "Lu par le client",
    contractualEmail: null,
};

function mountPage(props = {}) {
    return mount(CustomerPageApp, {
        global: { plugins: [i18n], stubs: { teleport: true } },
        props: {
            customer: CUSTOMER,
            related: {
                contracts: [
                    {
                        id: 3,
                        label: "CT-2026-003",
                        detail: "Brouillon",
                        status: "draft",
                        url: "/suite/studio/contracts/3",
                    },
                ],
                deliverables: null,
                spaces: [],
            },
            currencies: [{ value: "EUR", symbol: "€" }],
            indexPath: "/suite/studio/customers",
            updatePath: "/suite/studio/customers/12/update",
            convertPath: "/suite/studio/customers/__id__/convert",
            deletePath: "/suite/studio/customers/__id__/delete",
            ...props,
        },
    });
}

function saveButton(wrapper) {
    return wrapper
        .findAll("button")
        .find(
            (button) =>
                button.attributes("aria-label") === "Enregistrer" ||
                button.text().includes("Enregistrer"),
        );
}

/**
 * La page d'un client.
 *
 * Ce qui se casse en silence : un lecteur sans le droit de modifier qui
 * trouverait un formulaire actif et un bouton qui répond 403, ou un bouton
 * Enregistrer actif sur une fiche que personne n'a touchée.
 */
describe("CustomerPageApp", () => {
    beforeEach(() => {
        granted.clear();
    });

    it("fills the form with the whole record", async () => {
        granted.add("studio.customers.edit");
        const wrapper = mountPage();
        await flushPromises();

        const values = wrapper
            .findAll("input")
            .map((input) => input.element.value);
        expect(values).toContain("112817044");
        expect(values).toContain("04 76 00 00 00");
        expect(values).toContain("https://atelier.example.com");
        expect(wrapper.find("h1").text()).toBe("Atelier Temoin");
    });

    it("keeps Save disabled until something changes", async () => {
        granted.add("studio.customers.edit");
        const wrapper = mountPage();
        await flushPromises();

        expect(saveButton(wrapper).attributes("disabled")).toBeDefined();

        const siren = wrapper
            .findAll("input")
            .find((input) => "112817044" === input.element.value);
        await siren.setValue("732829320");

        expect(saveButton(wrapper).attributes("disabled")).toBeUndefined();
    });

    it("is read-only for whoever may only see customers", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(saveButton(wrapper)).toBeUndefined();
        expect(wrapper.find("form").attributes("inert")).toBeDefined();
        expect(wrapper.text()).toContain("customers.read_only");
    });

    it("lists what surrounds the customer, and not the lists the reader cannot open", async () => {
        const wrapper = mountPage();
        await flushPromises();

        expect(
            wrapper.find('a[href="/suite/studio/contracts/3"]').exists(),
        ).toBe(true);
        expect(wrapper.find('[data-related="deliverables"]').exists()).toBe(
            false,
        );
    });
});
