import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// `usePrivileges` lit `window.__isAdmin__` **au chargement du module**, pas au
// montage : posé plus bas, le drapeau arriverait après le composant qui s'en
// sert, l'écran se croirait en lecture seule et le formulaire n'existerait pas.
vi.hoisted(() => {
    window.__isAdmin__ = true;
});

import SpaceInformationView from "./SpaceInformationView.vue";

const i18n = createTestI18n();

/**
 * La fiche d'un client, remplie depuis son espace.
 *
 * **Ce qui est cassable en silence ici est le récapitulatif.** Il porte la
 * promesse « voici ce que votre client voit », et cette promesse décrit l'état
 * du serveur : s'il se mettait à suivre la frappe, il dirait au studio que le
 * client lit déjà ce qui n'est pas enregistré. Rien ne lèverait d'erreur.
 *
 * Le serveur a ses propres tests pour ce qu'il accepte et ce qu'il refuse.
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
        props: {
            information: FICHE,
            savePath: "/workspace/1/information/save",
            ...props,
        },
    });
}

/** Le formulaire vit dans une fenêtre, ouverte par « Modifier la fiche ». */
async function ouvrir(wrapper) {
    const bouton = wrapper
        .findAll("button")
        .find((b) => b.text().includes("space_information.edit"));
    await bouton.trigger("click");
    await flushPromises();
}

describe("SpaceInformationView", () => {
    beforeEach(() => {
        vi.stubGlobal(
            "fetch",
            vi.fn(() => Promise.resolve({ ok: false })),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("montre la fiche, et garde le formulaire fermé tant qu'on ne le demande pas", async () => {
        const wrapper = monter();
        await flushPromises();

        // On vient le plus souvent relire : une saisie ouverte d'office
        // faisait de chaque visite un formulaire.
        expect(wrapper.findAll("input")).toHaveLength(0);
        expect(wrapper.text()).toContain("Atelier Temoin");
    });

    it("remplit le formulaire avec la fiche enregistrée", async () => {
        const wrapper = monter();
        await flushPromises();
        await ouvrir(wrapper);

        const valeurs = wrapper.findAll("input").map((i) => i.element.value);
        expect(valeurs).toContain("Atelier Temoin");
        expect(valeurs).toContain("11281704400004");
        expect(valeurs).toContain("112817044");
    });

    it("ne montre pas les champs vides dans le récapitulatif", async () => {
        const wrapper = monter();
        await flushPromises();

        // Une fiche où chaque ligne est dessinée quand même est une fiche faite
        // de tirets : on y lit surtout ce qui manque.
        const recap = wrapper.findAll("dt").map((d) => d.text());
        expect(recap.join(" ")).toContain("siret");
        expect(recap.join(" ")).not.toContain("landline");
        expect(recap.join(" ")).not.toContain("postal_address");
    });

    it("le récapitulatif ne suit pas la frappe", async () => {
        const wrapper = monter();
        await flushPromises();
        await ouvrir(wrapper);

        const nom = wrapper
            .findAll("input")
            .find((i) => "Atelier Temoin" === i.element.value);
        await nom.setValue("Autre chose");
        await flushPromises();

        // Tant que rien n'est enregistré, « ce que le client voit » décrit ce
        // que le serveur porte, et non ce qu'on est en train de taper.
        const titres = wrapper.findAll("h3").map((h) => h.text());
        expect(titres).toContain("Atelier Temoin");
        expect(titres).not.toContain("Autre chose");
    });

    it("dit que la fiche vaut pour tous les espaces du client", async () => {
        const wrapper = monter();
        await flushPromises();

        // Modifier depuis un projet quelque chose qui vaut pour tous doit être
        // annoncé : sans cette ligne, l'écran ressemble à un réglage du projet.
        expect(wrapper.text()).toContain("space_information.scope");
    });

    it("annonce une fiche vide plutôt que de la dessiner en creux", async () => {
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
        await flushPromises();

        expect(wrapper.findAll("dt")).toHaveLength(0);
        expect(wrapper.text()).toContain("space_information.empty");
    });
});
