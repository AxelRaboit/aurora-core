import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// jsdom ne fournit pas `matchMedia`, et la bascule de thème de l'en-tête la
// demande **au chargement du module**, pas au montage. `vi.hoisted` place donc
// le remplaçant au-dessus des imports, que le moteur remonte de toute façon :
// posé plus bas, il arriverait après le composant qui s'en sert.
vi.hoisted(() => {
    window.matchMedia = (query) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener() {},
        removeEventListener() {},
        addListener() {},
        removeListener() {},
        dispatchEvent() {},
    });
});

import PublicSpaceApp from "./PublicSpaceApp.vue";

const i18n = createTestI18n();

/**
 * La page qu'un client ouvre par son lien, et ses onglets.
 *
 * **Elle n'avait aucun test de montage.** Elle empilait tout, donc il n'y
 * avait rien à choisir ni rien qui pouvait disparaître ; depuis qu'elle se lit
 * par onglets, trois règles sont devenues cassables en silence : l'onglet
 * d'arrivée, l'onglet qui n'existe pas faute de contenu, et la barre qui ne se
 * dessine pas quand il n'y a qu'un choix.
 *
 * Ce sont trois règles dont le défaut ne lève aucune erreur : une page qui
 * s'ouvre sur la discussion au lieu du calendrier, ou qui propose un onglet
 * vide, a l'air de marcher.
 */
const SPACE = {
    name: "Atelier Dupont - Réseaux sociaux",
    description: "Deux publications par semaine.",
    customerName: "Atelier Dupont",
    colourSlot: 1,
    timezone: "Europe/Paris",
};

const CHANNEL = {
    id: 1,
    name: "Général",
    kind: "main",
    isMain: true,
    isDirect: false,
    openToClient: true,
    members: [],
};

const FILE = {
    id: 7,
    title: "Charte graphique",
    originalName: "charte.pdf",
    mimeType: "application/pdf",
    size: 12_000,
    author: "Camille, gérante",
    fromClient: false,
    createdAt: "2026-09-18T09:00:00+00:00",
    url: "/spaces/a/b/files/7/file",
    preview: null,
};

function monter(props = {}) {
    return mount(PublicSpaceApp, {
        global: { plugins: [i18n], stubs: { teleport: true } },
        props: {
            space: SPACE,
            columns: [],
            items: [],
            comments: {},
            attachments: {},
            spaceFiles: [],
            chatChannels: [],
            chatMessages: [],
            chatReloadPath: "/spaces/a/b/chat/__channel__/messages",
            ...props,
        },
    });
}

/** Les onglets que la barre propose, dans l'ordre. */
function onglets(wrapper) {
    return wrapper
        .findAll("[role='group'] button")
        .map((b) => b.attributes("title"));
}

describe("PublicSpaceApp", () => {
    beforeEach(() => {
        vi.stubGlobal(
            "fetch",
            vi.fn(() => Promise.resolve({ ok: false })),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it("arrive sur le calendrier", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL], spaceFiles: [FILE] });
        await flushPromises();

        // Le premier bouton porte l'état actif : c'est le calendrier qu'on
        // vient voir, la discussion est la deuxième raison d'ouvrir la page.
        const boutons = wrapper.findAll("[role='group'] button");
        expect(boutons[0].attributes("aria-pressed")).toBe("true");
        expect(onglets(wrapper)[0]).toContain("tab_calendar");
    });

    it("ne propose pas la discussion quand aucun canal n'est lisible", async () => {
        const wrapper = monter({ spaceFiles: [FILE] });
        await flushPromises();

        // Deux onglets, donc la barre existe, mais pas celui-là : un onglet
        // vide se lit comme un écran inachevé.
        expect(onglets(wrapper)).toHaveLength(2);
        expect(onglets(wrapper).join(" ")).not.toContain("tab_chat");
    });

    it("ne propose pas les documents quand l'espace n'en a aucun", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL] });
        await flushPromises();

        expect(onglets(wrapper)).toHaveLength(2);
        expect(onglets(wrapper).join(" ")).not.toContain("tab_files");
    });

    it("ne dessine aucune barre quand il n'y a qu'un onglet", async () => {
        const wrapper = monter();
        await flushPromises();

        // Un sélecteur à un choix est un ornement, et il occuperait une ligne
        // sur un téléphone pour ne rien proposer.
        expect(wrapper.find("[role='group']").exists()).toBe(false);
    });

    it("ne propose pas les liens quand rien n'a été ouvert au client", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL], resources: [] });
        await flushPromises();

        // Ce qui n'a pas été ouvert n'arrive pas ici : la liste est vide, donc
        // l'onglet n'a rien à montrer et n'existe pas.
        expect(onglets(wrapper).join(" ")).not.toContain("tab_resources");
    });

    it("propose les liens dès qu'un élément a été ouvert", async () => {
        const wrapper = monter({
            chatChannels: [CHANNEL],
            resources: [
                {
                    id: 3,
                    kind: "link",
                    label: "Maquette Canva",
                    url: "https://canva.example.com/x",
                    body: null,
                },
            ],
        });
        await flushPromises();

        expect(onglets(wrapper).join(" ")).toContain("tab_resources");

        const liens = wrapper.findAll("[role='group'] button").at(-1);
        await liens.trigger("click");
        await flushPromises();

        expect(wrapper.text()).toContain("Maquette Canva");
    });

    it("ne propose pas la fiche quand elle ne dit rien de plus que le nom", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL], information: null });
        await flushPromises();

        expect(onglets(wrapper).join(" ")).not.toContain("tab_information");
    });

    it("propose la fiche dès qu'elle porte quelque chose", async () => {
        const wrapper = monter({
            chatChannels: [CHANNEL],
            information: {
                legalName: "Atelier Dupont",
                siret: "11281704400004",
                links: [],
                notes: null,
            },
        });
        await flushPromises();

        expect(onglets(wrapper).join(" ")).toContain("tab_information");

        const fiche = wrapper.findAll("[role='group'] button").at(-1);
        await fiche.trigger("click");
        await flushPromises();

        expect(wrapper.text()).toContain("11281704400004");
    });

    /**
     * Ce qui attend une réponse doit se voir sans ouvrir une seule carte, et
     * le compteur est aussi le filtre : un client qui revient veut sa liste de
     * tâches, pas son mois.
     */
    it("annonce ce qui attend une réponse, et seulement à qui peut répondre", async () => {
        const items = [
            {
                id: 1,
                title: "Sans réponse",
                columnId: 1,
                scheduledAt: "2026-09-24T09:00:00+00:00",
                approval: "pending",
            },
            {
                id: 2,
                title: "Validée",
                columnId: 1,
                scheduledAt: "2026-09-25T09:00:00+00:00",
                approval: "approved",
            },
        ];

        const lecteur = monter({ items });
        await flushPromises();
        // Un lien en lecture seule ne peut rien valider : lui annoncer ce qui
        // l'attend serait lui montrer une porte fermée.
        expect(lecteur.text()).not.toContain("awaiting_you");

        const wrapper = monter({ items, canApprove: true });
        await flushPromises();
        expect(wrapper.text()).toContain("awaiting_you");
    });

    it("ne garde que ce qui attend quand le filtre est enclenché", async () => {
        const items = [
            {
                id: 1,
                title: "Sans réponse",
                columnId: 1,
                scheduledAt: "2026-09-24T09:00:00+00:00",
                approval: "pending",
            },
            {
                id: 2,
                title: "Validée",
                columnId: 1,
                scheduledAt: "2026-09-25T09:00:00+00:00",
                approval: "approved",
            },
        ];

        const wrapper = monter({ items, canApprove: true });
        await flushPromises();

        const filtre = wrapper
            .findAll("button")
            .find((b) => b.text().includes("awaiting_you"));
        expect(filtre.attributes("aria-pressed")).toBe("false");

        await filtre.trigger("click");
        expect(filtre.attributes("aria-pressed")).toBe("true");
        // La carte déjà validée sort de la grille : c'est la même liste qui
        // alimente le mois et celle du jour.
        expect(wrapper.text()).not.toContain("Validée");
    });

    it("montre une seule section à la fois", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL], spaceFiles: [FILE] });
        await flushPromises();

        expect(wrapper.text()).not.toContain("Charte graphique");

        const documents = wrapper.findAll("[role='group'] button").at(-1);
        await documents.trigger("click");
        await flushPromises();

        expect(wrapper.text()).toContain("Charte graphique");
    });
});
