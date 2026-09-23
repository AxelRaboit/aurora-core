import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

window.__isAdmin__ = true;

const NoteLibrary = (await import("./NoteLibrary.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    {
        id: 1,
        parentId: null,
        name: "Clients",
        position: 0,
        noteCount: 2,
        folderCount: 1,
        updatedAt: "2026-09-01T10:00:00+00:00",
        createdAt: "2026-01-01T10:00:00+00:00",
    },
    {
        id: 2,
        parentId: 1,
        name: "Studio Lumen",
        position: 0,
        noteCount: 0,
        folderCount: 0,
        updatedAt: "2026-09-02T10:00:00+00:00",
        createdAt: "2026-02-01T10:00:00+00:00",
    },
];

const NOTES = [
    {
        id: 11,
        folderId: null,
        title: "À la racine",
        excerpt: "Les premières lignes de la note, en clair.",
        tags: ["essai"],
        position: 0,
        updatedAt: "2026-09-20T10:00:00+00:00",
        createdAt: "2026-04-01T10:00:00+00:00",
    },
    {
        id: 12,
        folderId: 1,
        title: "Devis",
        tags: [],
        position: 0,
        updatedAt: "2026-09-21T10:00:00+00:00",
        createdAt: "2026-05-01T10:00:00+00:00",
    },
];

function apis() {
    return {
        foldersApi: {
            list: vi.fn(),
            favorite: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            create: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            rename: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn(),
            urlFor: (id) => `/backend/notes/markdown/folder/${id}`,
        },
        notesApi: {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            favorite: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        },
    };
}

const mounted = [];

function render(props = {}) {
    const wrapper = mount(NoteLibrary, {
        props: {
            folders: FOLDERS,
            notes: NOTES,
            ...apis(),
            initialFolderId: null,
            breadcrumb: [],
            rootUrl: "/backend/notes/markdown",
            noteUrlFor: (id) => `/backend/notes/markdown/${id}`,
            ...props,
        },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    window.localStorage.clear();
    window.history.replaceState({}, "", "/backend/notes/markdown");
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    // Les feuilles d'actions se téléportent dans le body : sans ce coup de
    // balai, un cas retrouve le menu ouvert par le précédent.
    document.body.innerHTML = "";
    vi.restoreAllMocks();
});

describe("the library", () => {
    it("shows the root: its folders and the notes filed nowhere", () => {
        const cards = render()
            .findAll("article")
            .map((one) => one.text());

        expect(cards.some((text) => text.includes("Clients"))).toBe(true);
        expect(cards.some((text) => text.includes("À la racine"))).toBe(true);
        // Ce qui est rangé dans un dossier n'est pas à la racine. La rangée
        // des récentes, elle, traverse le carnet : c'est son travail.
        expect(cards.some((text) => text.includes("Devis"))).toBe(false);
    });

    /**
     * « Où en étais-je » est la question qu'on pose en arrivant, et la
     * rangée des récentes y répond sans faire chercher dans quel dossier la
     * note avait été rangée.
     */
    it("opens on the notes most recently changed, wherever they are filed", () => {
        const wrapper = render();

        const recent = wrapper.find("section");

        expect(recent.exists()).toBe(true);
        expect(recent.text()).toContain("Devis");
    });

    it("keeps the recent row out of a folder, where it would answer nothing", () => {
        expect(render({ initialFolderId: 1 }).find("section").exists()).toBe(
            false,
        );
    });

    it("shows what a folder holds when the address names one", () => {
        const text = render({ initialFolderId: 1 }).text();

        expect(text).toContain("Studio Lumen");
        expect(text).toContain("Devis");
        expect(text).not.toContain("À la racine");
    });

    /** Chaque carte est une adresse : le clic du milieu doit se comporter. */
    it("points a note card at the note's own address", () => {
        const hrefs = render()
            .findAll("a")
            .map((a) => a.attributes("href"));

        expect(hrefs).toContain("/backend/notes/markdown/11");
    });

    it("opens a note through the page rather than navigating", async () => {
        const wrapper = render();

        await wrapper
            .findAll("a")
            .find((a) => a.attributes("href") === "/backend/notes/markdown/11")
            .trigger("click");

        expect(wrapper.emitted("open-note")?.[0]).toEqual([11]);
    });

    it("asks the page for a new note in the folder being looked at", async () => {
        const wrapper = render({ initialFolderId: 1 });

        await wrapper
            .findAll("button")
            .find((b) => b.text().includes("new_note"))
            .trigger("click");

        expect(wrapper.emitted("create-note")?.[0]).toEqual([1]);
    });

    /**
     * Le geste central de la refonte : ranger en glissant une carte sur un
     * dossier. Ce qui est déplacé voyage dans le presse-papier de
     * l'événement, donc la cible n'a pas besoin de savoir ce qui arrive.
     */
    it("files a note into the folder it is dropped on", async () => {
        const notesApi = {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi });

        const dataTransfer = {
            types: ["application/x-aurora-note-item"],
            getData: () => "note:11",
            setData: () => {},
            effectAllowed: "",
            dropEffect: "",
        };

        const card = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await card.trigger("drop", { dataTransfer });
        await flushPromises();

        expect(notesApi.move).toHaveBeenCalledWith(11, 1);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    it("refuses to drop a folder onto itself", async () => {
        const foldersApi = {
            ...apis().foldersApi,
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ foldersApi });

        const dataTransfer = {
            types: ["application/x-aurora-note-item"],
            getData: () => "folder:1",
            setData: () => {},
            effectAllowed: "",
            dropEffect: "",
        };

        const card = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await card.trigger("drop", { dataTransfer });
        await flushPromises();

        expect(foldersApi.move).not.toHaveBeenCalled();
    });

    /**
     * L'extrait ne vaut que dans la mosaïque : la vue en cartes est dense
     * par choix, et la liste montre des colonnes.
     */
    it("shows the first lines in the mosaic, and only there", async () => {
        const wrapper = render();

        expect(wrapper.text()).toContain("Les premières lignes");

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.view.cards",
            )
            .trigger("click");

        expect(wrapper.text()).not.toContain("Les premières lignes");
    });

    /**
     * L'ordre manuel ne vaut que si quelque chose peut le changer : un
     * critère de tri sans geste pour l'alimenter est un menu qui ment.
     *
     * Le geste est dans le menu de la carte plutôt qu'au glisser : lâcher
     * une carte sur une autre veut déjà dire « range-la dedans », et
     * distinguer le bord du milieu d'une carte se rate au doigt.
     */
    it("moves a note within its folder, in manual order", async () => {
        window.localStorage.setItem("aurora.notes.library.sort", "manual");

        const notesApi = {
            move: vi.fn(),
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };

        const wrapper = render({
            notesApi,
            folders: [],
            notes: [
                { ...NOTES[0], position: 0 },
                {
                    id: 14,
                    folderId: null,
                    title: "Seconde",
                    tags: [],
                    position: 1,
                    updatedAt: "2026-09-19T10:00:00+00:00",
                    createdAt: "2026-06-01T10:00:00+00:00",
                },
            ],
        });

        // Ordre manuel décroissant par défaut : « Seconde », position 1,
        // est en tête, et c'est « À la racine » qui peut monter.
        const first = wrapper
            .findAll("article")
            .find((one) => one.text().includes("À la racine"));

        // Le premier bouton d'une carte est sa case à cocher ; le menu est
        // le dernier.
        await first.findAll("button").at(-1).trigger("click");
        await flushPromises();

        const up = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("sort.move_up"),
        );
        expect(up, "l'action monter est proposée").toBeTruthy();

        up.click();
        await flushPromises();

        // Monter d'un cran en ordre décroissant, c'est prendre la position
        // la plus haute : les deux notes échangent leurs rangs.
        expect(notesApi.reorder).toHaveBeenCalledWith([
            { id: 14, folderId: null, position: 0 },
            { id: 11, folderId: null, position: 1 },
        ]);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    it("keeps the order actions out of a sort that would undo them", async () => {
        const wrapper = render({ folders: [] });

        await wrapper
            .findAll("article")[0]
            .findAll("button")
            .at(-1)
            .trigger("click");
        await flushPromises();

        expect(document.body.textContent).not.toContain("sort.move_up");
    });

    /**
     * Se défaire d'une note sans avoir à l'ouvrir : elle ne se supprimait
     * que depuis l'éditeur, donc il fallait lire ce qu'on voulait jeter.
     */
    it("deletes a note from the library, after asking", async () => {
        const notesApi = {
            move: vi.fn(),
            reorder: vi.fn(),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi, folders: [] });

        await wrapper
            .findAll("article")[0]
            .findAll("button")
            .at(-1)
            .trigger("click");
        await flushPromises();

        const remove = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("markdown.delete"),
        );
        expect(remove, "l'action supprimer est proposée").toBeTruthy();
        remove.click();
        await flushPromises();

        // La feuille d'actions et la confirmation portent le même libellé ;
        // celle qui vient d'apparaître est la dernière du document.
        const confirm = [...document.body.querySelectorAll("button")]
            .filter((b) => b.textContent.includes("markdown.delete"))
            .at(-1);
        expect(confirm, "la confirmation est à l'écran").toBeTruthy();
        confirm.click();
        await flushPromises();

        expect(notesApi.remove).toHaveBeenCalledWith(11);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    /**
     * Ranger un carnet, c'est rarement déplacer une note : c'est en
     * déplacer douze. La sélection existe pour ces deux gestes-là, et pour
     * aucun autre - renommer ou exporter n'a pas de sens au pluriel.
     */
    it("moves everything that is selected, in one dialog", async () => {
        const notesApi = {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn(),
            remove: vi.fn(),
        };
        const foldersApi = {
            ...apis().foldersApi,
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi, foldersApi });

        // Une note et un dossier : la sélection porte les deux natures, et
        // un identifiant seul les aurait confondus.
        const cards = wrapper.findAll("article");
        await cards[0].findAll("button")[0].trigger("click");
        await cards.at(-1).findAll("button")[0].trigger("click");

        expect(wrapper.text()).toContain("library.selected");

        await wrapper
            .findAll("button")
            .find((b) => b.text().includes("folders.move_to"))
            .trigger("click");
        await flushPromises();

        const confirm = [...document.body.querySelectorAll("button")]
            .filter((b) => b.textContent.includes("folders.move_to"))
            .at(-1);
        confirm.click();
        await flushPromises();

        expect(foldersApi.move).toHaveBeenCalledWith(1, null);
        expect(notesApi.move).toHaveBeenCalledWith(11, null);
        expect(wrapper.text()).not.toContain("library.selected");
    });

    it("forgets the selection when the folder changes", async () => {
        const wrapper = render();

        await wrapper
            .findAll("article")[0]
            .findAll("button")[0]
            .trigger("click");
        expect(wrapper.text()).toContain("library.selected");

        wrapper.vm.openFolder(1);
        await flushPromises();

        expect(wrapper.text()).not.toContain("library.selected");
    });

    /**
     * Le clavier, parce qu'un explorateur sans flèches oblige à viser.
     */
    describe("au clavier", () => {
        function press(key) {
            window.dispatchEvent(
                new KeyboardEvent("keydown", { key, bubbles: true }),
            );

            return flushPromises();
        }

        it("walks the cards and opens the one it stops on", async () => {
            const wrapper = render({ folders: [] });

            await press("ArrowDown");
            await press("Enter");

            expect(wrapper.emitted("open-note")?.[0]).toEqual([11]);
        });

        it("picks and unpicks with the space bar", async () => {
            const wrapper = render({ folders: [] });

            await press("ArrowDown");
            await press(" ");
            expect(wrapper.text()).toContain("library.selected");

            await press("Escape");
            expect(wrapper.text()).not.toContain("library.selected");
        });

        it("makes a note with n, and asks for a folder name with N", async () => {
            const wrapper = render();

            await press("n");
            expect(wrapper.emitted("create-note")?.[0]).toEqual([null]);

            await press("N");
            expect(document.body.textContent).toContain("folders.create");
        });

        /**
         * Le piège du raccourci à une lettre : taper « nouvelle » dans la
         * recherche créerait une note par « n ».
         */
        it("keeps its hands off the keyboard while somebody types", async () => {
            const wrapper = render();
            const input = wrapper.find("input");

            input.element.dispatchEvent(
                new KeyboardEvent("keydown", { key: "n", bubbles: true }),
            );
            await flushPromises();

            expect(wrapper.emitted("create-note")).toBeUndefined();
        });
    });

    it("renames a folder on a double click, as a file browser does", async () => {
        const wrapper = render();

        const folderCard = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await folderCard.findAll("button")[1].trigger("dblclick");

        expect(document.body.textContent).toContain("folders.rename");
    });

    it("pins a note to the side menu", async () => {
        const notesApi = {
            move: vi.fn(),
            reorder: vi.fn(),
            remove: vi.fn(),
            favorite: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi, folders: [] });

        await wrapper
            .findAll("article")[0]
            .findAll("button")
            .at(-1)
            .trigger("click");
        await flushPromises();

        const pin = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("library.pin"),
        );
        expect(pin, "l'action épingler est proposée").toBeTruthy();
        pin.click();
        await flushPromises();

        expect(notesApi.favorite).toHaveBeenCalledWith(11);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    /**
     * Le défaut qui a rendu la page blanche : le serveur envoyait ses dates
     * en objets, `Intl` levait, et l'exception emportait le composant
     * entier. Le serveur est corrigé ; ceci vérifie que l'affichage tient
     * même si une date redevenait illisible.
     */
    it("survives a date it cannot read", () => {
        const wrapper = render({
            folders: [],
            notes: [
                {
                    ...NOTES[0],
                    updatedAt: { date: "2026-09-23 04:59:53", timezone: "UTC" },
                    createdAt: null,
                },
            ],
        });

        expect(wrapper.findAll("article")).toHaveLength(1);
        expect(wrapper.text()).toContain("À la racine");
    });

    it("draws a table when the list view is picked", async () => {
        const wrapper = render();

        expect(wrapper.find("table").exists()).toBe(false);

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.view.list",
            )
            .trigger("click");

        expect(wrapper.find("table").exists()).toBe(true);
    });

    it("filters what is on screen on what the reader typed", async () => {
        const wrapper = render();

        await wrapper.find("input").setValue("racine");
        await flushPromises();

        expect(wrapper.text()).toContain("À la racine");
        expect(wrapper.text()).not.toContain("Clients");
    });
});
