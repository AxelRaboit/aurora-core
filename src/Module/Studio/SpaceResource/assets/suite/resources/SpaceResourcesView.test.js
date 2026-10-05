import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// `usePrivileges` lit `window.__isAdmin__` **au chargement du module**, pas au
// montage : posé plus bas, le drapeau arriverait après le composant qui s'en
// sert, l'écran se croirait en lecture seule et les boutons n'existeraient
// pas - un test qui passerait sans rien éprouver.
vi.hoisted(() => {
    window.__isAdmin__ = true;
});

import SpaceResourcesView from "./SpaceResourcesView.vue";

const i18n = createTestI18n();

/**
 * Les ressources d'un espace, vues du studio.
 *
 * **Ce qui est cassable en silence ici est la séparation des deux listes.**
 * L'écran range au même endroit ce qui se partage et ce qui ne se partage pas ;
 * un élément fermé qui passerait du mauvais côté aurait l'air de marcher, et
 * ne se verrait qu'en comparant avec la page du client.
 *
 * Le serveur a ses propres tests pour la garantie elle-même : un élément fermé
 * ne sort pas du serveur. Ceci vérifie ce que le studio lit, qui est l'autre
 * moitié - il doit pouvoir savoir d'un coup d'œil ce qu'il a publié.
 */
const PATHS = {
    resourceCreatePath: "/workspace/1/resources/create",
    resourceUpdatePath: "/workspace/1/resources/__id__/update",
    resourceVisibilityPath: "/workspace/1/resources/__id__/visibility",
    resourceDeletePath: "/workspace/1/resources/__id__/delete",
    resourceReorderPath: "/workspace/1/resources/reorder",
};

const OUVERT = {
    id: 1,
    kind: "link",
    label: "Maquette Canva",
    url: "https://canva.example.com/x",
    body: null,
    email: null,
    phone: null,
    visibleToClient: true,
};
const FERME = {
    id: 2,
    kind: "text",
    label: "Facturation",
    url: null,
    body: "Mensuelle, le 5.",
    email: null,
    phone: null,
    visibleToClient: false,
};

function monter(props = {}) {
    return mount(SpaceResourcesView, {
        global: { plugins: [i18n], stubs: { teleport: true } },
        props: { resources: [], ...PATHS, ...props },
    });
}

describe("SpaceResourcesView", () => {
    beforeEach(() => {
        vi.stubGlobal(
            "fetch",
            vi.fn(() => Promise.resolve({ ok: false })),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("sépare ce que le client voit de ce qui reste entre nous", async () => {
        const wrapper = monter({ resources: [OUVERT, FERME] });
        await flushPromises();

        const titres = wrapper.findAll("section h3").map((h) => h.text());
        expect(titres).toHaveLength(2);
        expect(titres[0]).toContain("group_shown");
        expect(titres[1]).toContain("group_hidden");

        // Chacun sous son titre, et non mélangés avec une pastille à repérer.
        const sections = wrapper.findAll("section");
        expect(sections[0].text()).toContain("Maquette Canva");
        expect(sections[0].text()).not.toContain("Facturation");
        expect(sections[1].text()).toContain("Facturation");
    });

    it("ne dessine aucun groupe tant que rien n'est épinglé", async () => {
        const wrapper = monter();
        await flushPromises();

        expect(wrapper.findAll("section")).toHaveLength(0);
        expect(wrapper.text()).toContain("empty");
    });

    it("nomme le geste d'après l'état de la ligne", async () => {
        const wrapper = monter({ resources: [OUVERT, FERME] });
        await flushPromises();

        const sections = wrapper.findAll("section");

        // Une ligne ouverte propose de la retirer, une ligne fermée de la
        // montrer. Un libellé unique aurait obligé à lire l'icône pour savoir
        // dans quel sens on va, sur le seul geste de l'écran qui publie.
        expect(sections[0].text()).toContain("space_resources.hide");
        expect(sections[1].text()).toContain("space_resources.show");
    });

    it("ne dessine qu'un groupe quand tout est du même côté", async () => {
        const wrapper = monter({ resources: [FERME] });
        await flushPromises();

        // Un titre « ce que le client voit » au-dessus d'une liste vide
        // annoncerait une publication qui n'a pas eu lieu.
        const titres = wrapper.findAll("section h3").map((h) => h.text());
        expect(titres).toHaveLength(1);
        expect(titres[0]).toContain("group_hidden");
    });
});
