import { describe, it, expect, vi } from "vitest";
import { mount } from "@vue/test-utils";
import { useCustomerRowActions } from "./useCustomerRowActions.js";

vi.mock("vue-i18n", () => ({ useI18n: () => ({ t: (key) => key }) }));

function build(granted, extra = {}) {
    const wrapper = mount({
        setup() {
            return {
                build: useCustomerRowActions({
                    showPath: "/suite/studio/customers/__id__",
                    can: (permission) => granted.includes(permission),
                    convertToClient: vi.fn(),
                    confirmDelete: vi.fn(),
                    ...extra,
                }),
            };
        },
        template: "<i />",
    });

    return wrapper.vm.build;
}

function actions(
    record,
    granted = ["studio.customers.edit", "studio.customers.delete"],
) {
    return build(granted)(record).map((action) => action.key);
}

describe("useCustomerRowActions", () => {
    it("offers converting on a prospect and on nothing else", () => {
        // Un client est deja converti : une entree grisee sur deux lignes sur
        // trois est du bruit dans un menu qu'on lit vite.
        expect(actions({ id: 1, status: "prospect" })).toEqual([
            "open",
            "convert",
            "delete",
        ]);

        expect(actions({ id: 2, status: "client" })).toEqual([
            "open",
            "delete",
        ]);
    });

    it("does not offer converting to somebody who may not edit", () => {
        expect(
            actions({ id: 1, status: "prospect" }, ["studio.customers.delete"]),
        ).toEqual(["open", "delete"]);
    });

    it("opens the customer page as a link, for whoever sees the list", () => {
        // La fiche ne se modifie plus dans une fenetre de la liste : on va sur
        // sa page, et un lien peut s'ouvrir dans un autre onglet.
        const open = build([])({ id: 42, status: "client" }).find(
            (action) => "open" === action.key,
        );

        expect(open.href).toBe("/suite/studio/customers/42");
        expect(open.onSelect).toBeUndefined();
    });

    it("no longer offers an edit entry", () => {
        expect(actions({ id: 1, status: "client" })).not.toContain("edit");
    });

    it("opens the spaces of this company by id, not by a name search", () => {
        const spaces = build(["studio.spaces.view"], {
            spacesPath: "/suite/studio/spaces",
        })({ id: 7, status: "client", legalName: "Atelier Dupont" }).find(
            (action) => "spaces" === action.key,
        );

        expect(spaces.href).toBe("/suite/studio/spaces?customer=7");
    });

    it("keeps the destructive entry last", () => {
        expect(actions({ id: 1, status: "prospect" }).at(-1)).toBe("delete");
    });
});
