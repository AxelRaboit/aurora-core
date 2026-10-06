import { describe, it, expect } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import SpaceInformationView from "./SpaceInformationView.vue";

const i18n = createTestI18n();

/**
 * La fiche d'un client, vue depuis son espace.
 *
 * **En lecture.** Elle se modifie sur la page du client, et l'onglet y mène.
 * Ce qui se casserait en silence : un formulaire qui reviendrait ici, et la
 * fiche aurait de nouveau deux endroits pour s'écrire, avec deux listes de
 * champs.
 */
const FICHE = {
    legalName: "Atelier Temoin",
    siret: "11281704400004",
    siren: "112817044",
    phone: "06 12 34 56 78",
    landline: null,
    email: "contact@atelier.example.com",
    postalAddress: null,
    links: [{ label: "Site web", url: "https://atelier.example.com" }],
    notes: null,
};

function monter(props = {}) {
    return mount(SpaceInformationView, {
        global: { plugins: [i18n], stubs: { teleport: true } },
        props: { information: FICHE, ...props },
    });
}

describe("SpaceInformationView", () => {
    it("montre la fiche, sans aucun champ de saisie", () => {
        const wrapper = monter({ customerPath: "/suite/studio/customers/3" });

        expect(wrapper.findAll("input")).toHaveLength(0);
        expect(wrapper.findAll("textarea")).toHaveLength(0);
        expect(wrapper.find("form").exists()).toBe(false);
        expect(wrapper.text()).toContain("Atelier Temoin");
    });

    it("mène à la page du client pour qui peut modifier la fiche", () => {
        const wrapper = monter({ customerPath: "/suite/studio/customers/3" });

        const lien = wrapper.find('a[href="/suite/studio/customers/3"]');
        expect(lien.exists()).toBe(true);
        expect(lien.text()).toContain("space_information.edit");
    });

    it("ne propose rien à qui ne peut pas la modifier", () => {
        const wrapper = monter({ customerPath: null });

        expect(wrapper.text()).not.toContain("space_information.edit");
    });

    it("ne montre pas les champs vides dans le récapitulatif", () => {
        const recap = monter()
            .findAll("dt")
            .map((d) => d.text())
            .join(" ");

        expect(recap).toContain("siret");
        expect(recap).not.toContain("landline");
        expect(recap).not.toContain("registered_office");
    });

    it("dit que la fiche vaut pour tous les espaces du client", () => {
        expect(monter().text()).toContain("space_information.scope");
    });

    it("liste ce qui entoure le client, sans l'espace où l'on est", () => {
        const wrapper = monter({
            related: {
                contracts: [
                    {
                        label: "CT-2026-001",
                        detail: "Conclu",
                        url: "/suite/studio/contracts/1",
                    },
                ],
                deliverables: null,
                spaces: [
                    {
                        label: "Second projet",
                        detail: null,
                        url: "/workspace/9/content",
                    },
                ],
            },
        });

        expect(wrapper.text()).toContain("CT-2026-001");
        expect(wrapper.text()).toContain("related.other_spaces");
        expect(wrapper.find('[data-related="deliverables"]').exists()).toBe(
            false,
        );
    });

    it("annonce une fiche vide plutôt que de la dessiner en creux", () => {
        const wrapper = monter({
            information: {
                legalName: "Societe Nue",
                siret: null,
                siren: null,
                phone: null,
                landline: null,
                email: null,
                postalAddress: null,
                links: [],
                notes: null,
            },
        });

        expect(wrapper.findAll("dt")).toHaveLength(0);
        expect(wrapper.text()).toContain("space_information.empty");
    });
});
