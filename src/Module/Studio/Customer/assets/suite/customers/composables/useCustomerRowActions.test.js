import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useCustomerRowActions } from "./useCustomerRowActions.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

function actions(
    record,
    granted = ["studio.customers.edit", "studio.customers.delete"],
) {
    const wrapper = mount({
        setup() {
            return {
                build: useCustomerRowActions({
                    can: (permission) => granted.includes(permission),
                    openEdit: vi.fn(),
                    convertToClient: vi.fn(),
                    confirmDelete: vi.fn(),
                }),
            };
        },
        template: "<i />",
    });

    return wrapper.vm.build(record).map((action) => action.key);
}

describe("useCustomerRowActions", () => {
    it("offers converting on a prospect and on nothing else", () => {
        // Un client est deja converti : une entree grisee sur deux lignes sur
        // trois est du bruit dans un menu qu'on lit vite.
        expect(actions({ id: 1, status: "prospect" })).toEqual([
            "edit",
            "convert",
            "delete",
        ]);

        expect(actions({ id: 2, status: "client" })).toEqual([
            "edit",
            "delete",
        ]);
    });

    it("does not offer converting to somebody who may not edit", () => {
        expect(
            actions({ id: 1, status: "prospect" }, ["studio.customers.delete"]),
        ).toEqual(["delete"]);
    });

    it("opens the spaces of this company by id, not by a name search", () => {
        const wrapper = mount({
            setup() {
                return {
                    build: useCustomerRowActions({
                        spacesPath: "/suite/studio/spaces",
                        can: () => true,
                        openEdit: vi.fn(),
                        convertToClient: vi.fn(),
                        confirmDelete: vi.fn(),
                    }),
                };
            },
            template: "<i />",
        });

        const spaces = wrapper.vm
            .build({ id: 7, status: "client", legalName: "Atelier Dupont" })
            .find((action) => "spaces" === action.key);

        expect(spaces.href).toBe("/suite/studio/spaces?customer=7");
    });

    it("keeps the destructive entry last", () => {
        expect(actions({ id: 1, status: "prospect" }).at(-1)).toBe("delete");
    });
});
