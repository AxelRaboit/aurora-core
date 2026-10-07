import { describe, it, expect, vi, beforeEach } from "vitest";
import { ref } from "vue";
import { mount, flushPromises } from "@vue/test-utils";

const toast = { success: vi.fn(), error: vi.fn() };
const queueFlash = vi.fn();
let answer = { success: true };
const sent = [];

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));
vi.mock("vue-sonner", () => ({ toast }));
vi.mock("@/shared/utils/flash.js", () => ({ queueFlash }));
vi.mock("@/shared/composables/http/suite/useRequest.js", () => ({
    useRequest: () => ({
        loading: ref(false),
        request: (url, body) => {
            sent.push({ url, body });

            return Promise.resolve(answer);
        },
    }),
}));

const { useCustomerPage } = await import("./useCustomerPage.js");

const RECORD = {
    id: 12,
    legalName: "Atelier Temoin",
    status: "prospect",
    statusLabel: "suite.studio.customers.statuses.prospect",
    siret: "11281704400004",
    siren: null,
    contractualEmail: null,
    phone: null,
    landline: null,
    links: [],
    informationNotes: null,
};

function page(record = RECORD) {
    let api;
    mount({
        setup() {
            api = useCustomerPage({
                customer: record,
                indexPath: "/suite/studio/customers",
                updatePath: "/suite/studio/customers/12/update",
                convertPath: "/suite/studio/customers/__id__/convert",
                deletePath: "/suite/studio/customers/__id__/delete",
            });

            return {};
        },
        template: "<i />",
    });

    return api;
}

describe("useCustomerPage", () => {
    beforeEach(() => {
        toast.success.mockClear();
        queueFlash.mockClear();
        sent.length = 0;
        answer = { success: true };
    });

    it("is clean until something is typed", () => {
        const api = page();

        expect(api.dirty.value).toBe(false);
        api.form.value.siren = "112817044";
        expect(api.dirty.value).toBe(true);
    });

    it("sends the whole record, once, to the one route that writes it", async () => {
        const api = page();
        api.form.value.siren = "112817044";
        api.form.value.landline = "01 23 45 67 89";
        api.form.value.links = [
            { label: "Site", url: "https://atelier.example.com" },
        ];
        api.form.value.informationNotes = "Lu par le client";

        answer = {
            success: true,
            customer: {
                ...RECORD,
                siren: "112817044",
                landline: "01 23 45 67 89",
                links: api.form.value.links,
                informationNotes: "Lu par le client",
            },
        };
        await api.save();
        await flushPromises();

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe("/suite/studio/customers/12/update");
        expect(sent[0].body).toMatchObject({
            legalName: "Atelier Temoin",
            siret: "11281704400004",
            siren: "112817044",
            landline: "01 23 45 67 89",
            informationNotes: "Lu par le client",
        });
        // The sheet read again becomes the saved state: nothing is in progress anymore.
        expect(api.customer.value.siren).toBe("112817044");
        expect(api.dirty.value).toBe(false);
        expect(toast.success).toHaveBeenCalledWith(
            "suite.studio.customers.updated",
        );
    });

    it("pins a server refusal under its field, and keeps what was typed", async () => {
        const api = page();
        api.form.value.siren = "732456306";

        answer = {
            success: false,
            errors: { siren: "suite.studio.customers.errors.siren_mismatch" },
        };
        await api.save();
        await flushPromises();

        expect(api.errors.value.siren).toBe(
            "suite.studio.customers.errors.siren_mismatch",
        );
        expect(api.form.value.siren).toBe("732456306");
        expect(api.dirty.value).toBe(true);
    });

    it("converts without losing what is being typed elsewhere in the form", async () => {
        const api = page();
        api.form.value.landline = "01 23 45 67 89";

        api.openConversion();
        api.conversion.email.value = "contact@atelier.example.com";

        answer = {
            success: true,
            customers: [
                {
                    ...RECORD,
                    status: "client",
                    statusLabel: "suite.studio.customers.statuses.client",
                    contractualEmail: "contact@atelier.example.com",
                    spaces: [],
                    contracts: null,
                },
            ],
        };
        await api.conversion.submit();
        await flushPromises();

        expect(api.customer.value.status).toBe("client");
        expect(api.customer.value).not.toHaveProperty("spaces");
        expect(api.form.value.status).toBe("client");
        expect(api.form.value.contractualEmail).toBe(
            "contact@atelier.example.com",
        );
        // The input in progress survives the conversion.
        expect(api.form.value.landline).toBe("01 23 45 67 89");
    });

    it("goes back to the list after a deletion, and the message follows", async () => {
        const assign = vi.fn();
        const original = window.location;
        Object.defineProperty(window, "location", {
            configurable: true,
            value: {
                set href(value) {
                    assign(value);
                },
                get href() {
                    return "";
                },
            },
        });

        try {
            const api = page();
            api.confirmDelete(api.customer.value);
            await api.doDelete();
            await flushPromises();

            expect(queueFlash).toHaveBeenCalledWith(
                "success",
                "suite.studio.customers.deleted",
            );
            expect(assign).toHaveBeenCalledWith("/suite/studio/customers");
        } finally {
            Object.defineProperty(window, "location", {
                configurable: true,
                value: original,
            });
        }
    });

    it("stays on the page when the deletion is refused", async () => {
        const api = page();
        api.confirmDelete(api.customer.value);

        answer = {
            success: false,
            errors: { customer: "Ce client est nommé par 1 contrat(s)." },
        };
        await api.doDelete();
        await flushPromises();

        expect(queueFlash).not.toHaveBeenCalled();
        expect(toast.error).toHaveBeenCalledWith(
            "Ce client est nommé par 1 contrat(s).",
        );
    });
});
