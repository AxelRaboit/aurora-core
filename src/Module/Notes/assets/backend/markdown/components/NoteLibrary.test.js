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
            create: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            rename: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn(),
            urlFor: (id) => `/backend/notes/markdown/folder/${id}`,
        },
        notesApi: {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
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
    vi.restoreAllMocks();
});

describe("the library", () => {
    it("shows the root: its folders and the notes filed nowhere", () => {
        const text = render().text();

        expect(text).toContain("Clients");
        expect(text).toContain("À la racine");
        // Ce qui est rangé dans un dossier n'est pas à la racine.
        expect(text).not.toContain("Devis");
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
