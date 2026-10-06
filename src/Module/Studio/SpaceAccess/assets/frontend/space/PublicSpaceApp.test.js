import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
// jsdom does not provide `matchMedia`, and the header's theme toggle asks for
// it **when the module loads**, not on mount. `vi.hoisted` therefore puts the
// stand-in above the imports, which the engine hoists anyway: placed lower, it
// would arrive after the component that uses it.
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
 * The page a client opens through their link, and its tabs.
 *
 * **It had no mount test.** It stacked everything, so there was nothing to
 * choose and nothing that could disappear; since it is read in tabs, three
 * rules can break silently: the landing tab, the tab that does not exist for
 * lack of content, and the bar that is not drawn when there is only one
 * choice.
 *
 * These are three rules whose failure raises no error: a page that opens on
 * the chat instead of the calendar, or that offers an empty tab, looks like it
 * works.
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

/** The tabs the bar offers, in order. */
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

        // The first button carries the active state: the calendar is what
        // people come to see, the chat is the second reason to open the page.
        const boutons = wrapper.findAll("[role='group'] button");
        expect(boutons[0].attributes("aria-pressed")).toBe("true");
        expect(onglets(wrapper)[0]).toContain("tab_calendar");
    });

    it("ne propose pas la discussion quand aucun canal n'est lisible", async () => {
        const wrapper = monter({ spaceFiles: [FILE] });
        await flushPromises();

        // Two tabs, so the bar exists, but not that one: an empty tab reads
        // as an unfinished screen.
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

        // A selector with one choice is an ornament, and it would take up a
        // line on a phone to offer nothing.
        expect(wrapper.find("[role='group']").exists()).toBe(false);
    });

    it("ne propose pas les liens quand rien n'a été ouvert au client", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL], resources: [] });
        await flushPromises();

        // What was not opened never gets here: the list is empty, so the tab
        // has nothing to show and does not exist.
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
     * What is waiting for an answer must be visible without opening a single
     * card, and the counter is also the filter: a client coming back wants
     * their task list, not their month.
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
        // A read-only link cannot approve anything: telling it what is waiting
        // would be showing it a closed door.
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
        // The card already approved leaves the grid: the same list feeds the
        // month and the day list.
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

    /** The how-to guide's steps, by their key. */
    function etapes(wrapper) {
        return wrapper
            .findAll("[data-guide] li")
            .map((li) =>
                li.text().replace("studio.public.space.guide.step_", ""),
            );
    }

    it("ne décrit que ce que le lien permet", async () => {
        const lecture = monter({ canApprove: false, canComment: false });
        await flushPromises();

        // A read-only link: no step promising a button missing from the page.
        expect(etapes(lecture)).toEqual(["calendar"]);

        const complet = monter({
            canApprove: true,
            canComment: true,
            canUpload: true,
            chatChannels: [CHANNEL],
            chatPostPath: "/spaces/a/b/chat/__channel__/messages/new",
            spaceFiles: [FILE],
        });
        await flushPromises();

        expect(etapes(complet)).toEqual([
            "calendar",
            "answer",
            "comment_upload",
            "chat",
            "tabs",
        ]);
    });

    it("dit la discussion en lecture quand le lien ne peut pas y écrire", async () => {
        const wrapper = monter({ chatChannels: [CHANNEL] });
        await flushPromises();

        expect(etapes(wrapper)).toContain("chat_read");
        expect(etapes(wrapper)).not.toContain("chat");
    });

    describe("envoyer un fichier à l'espace", () => {
        const PATH = "/spaces/a/b/files";

        /** Opens the Files tab, the last one when only calendar and files exist. */
        async function ouvrirFichiers(wrapper) {
            await wrapper
                .findAll("[role='group'] button")
                .at(-1)
                .trigger("click");
            await flushPromises();
        }

        it("ne dessine ni bouton ni onglet sans le droit d'envoyer", async () => {
            const wrapper = monter();
            await flushPromises();

            expect(
                wrapper.find("[data-test='space-file-upload']").exists(),
            ).toBe(false);
            expect(onglets(wrapper)).toEqual([]);
        });

        it("ouvre l'onglet Fichiers pour envoyer le premier, même vide", async () => {
            const wrapper = monter({ spaceFileUploadPath: PATH });
            await flushPromises();

            expect(onglets(wrapper).join(" ")).toContain("tab_files");

            await ouvrirFichiers(wrapper);

            expect(
                wrapper.find("[data-test='space-file-upload']").exists(),
            ).toBe(true);
            expect(wrapper.text()).toContain("studio.public.space.files_empty");
        });

        it("envoie le fichier choisi et remplace la liste par la réponse", async () => {
            const sent = { ...FILE, id: 9, title: "Logo", fromClient: true };
            const fetchMock = vi.fn(() =>
                Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            success: true,
                            spaceFiles: [sent, FILE],
                        }),
                }),
            );
            vi.stubGlobal("fetch", fetchMock);

            const wrapper = monter({
                spaceFileUploadPath: PATH,
                spaceFiles: [FILE],
            });
            await flushPromises();
            await ouvrirFichiers(wrapper);

            const input = wrapper.find("[data-test='space-file-input']");
            Object.defineProperty(input.element, "files", {
                value: [new File(["x"], "logo.png", { type: "image/png" })],
                configurable: true,
            });
            await input.trigger("change");
            await flushPromises();

            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(fetchMock.mock.calls[0][0]).toBe(PATH);
            expect(fetchMock.mock.calls[0][1].body).toBeInstanceOf(FormData);
            expect(fetchMock.mock.calls[0][1].headers["X-Requested-With"]).toBe(
                "XMLHttpRequest",
            );
            expect(wrapper.text()).toContain("Logo");
            // Who sent it, said on the client side too.
            expect(wrapper.text()).toContain(
                "studio.public.space.files_sent_by",
            );
        });

        it("ne peut pas envoyer depuis un aperçu", async () => {
            const fetchMock = vi.fn();
            vi.stubGlobal("fetch", fetchMock);

            const wrapper = monter({
                spaceFileUploadPath: PATH,
                preview: true,
            });
            await flushPromises();
            await ouvrirFichiers(wrapper);

            expect(
                wrapper
                    .find("[data-test='space-file-upload']")
                    .attributes("disabled"),
            ).toBeDefined();

            const input = wrapper.find("[data-test='space-file-input']");
            Object.defineProperty(input.element, "files", {
                value: [new File(["x"], "logo.png", { type: "image/png" })],
                configurable: true,
            });
            await input.trigger("change");
            await flushPromises();

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it("ajoute l'étape d'envoi au mode d'emploi", async () => {
            const wrapper = monter({ spaceFileUploadPath: PATH });
            await flushPromises();

            expect(etapes(wrapper)).toContain("files_upload");
        });
    });
});
