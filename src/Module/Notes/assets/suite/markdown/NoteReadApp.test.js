import { describe, it, expect, vi, afterEach } from "vitest";
import { mount } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import NoteReadApp from "./NoteReadApp.vue";

const i18n = createTestI18n();

const BASE = {
    noteId: 5,
    noteTitle: "Chapitre deux",
    content: "Du texte",
    readNotePath: "/notes/__id__/read",
    libraryPath: "/notes",
    folderShowPath: "/notes/folder/__id__",
    backPath: "/notes/5",
    treeFolders: [
        { id: 1, name: "Micro-entreprise", parentId: null, position: 0 },
        { id: 4, name: "Comptabilité", parentId: 1, position: 0 },
    ],
    treeNotes: [
        { id: 4, title: "Chapitre un", folderId: 4, position: 0 },
        { id: 5, title: "Chapitre deux", folderId: 4, position: 1 },
        { id: 9, title: "À la racine", folderId: null, position: 0 },
    ],
};

const mounted = [];

function render(props = {}) {
    const wrapper = mount(NoteReadApp, {
        props: { ...BASE, ...props },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });
    mounted.push(wrapper);

    return wrapper;
}

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe("le mode lecture", () => {
    it("writes where the note lives, from the root", () => {
        const wrapper = render({
            breadcrumb: [
                { id: 1, name: "Micro-entreprise", color: null },
                { id: 4, name: "Comptabilité", color: null },
            ],
        });

        expect(
            wrapper.findAll("[data-read-crumb]").map((one) => one.text()),
        ).toEqual(["Micro-entreprise", "Comptabilité"]);
        expect(wrapper.find("[data-read-crumb]").attributes("href")).toBe(
            "/notes/folder/1",
        );
    });

    it("turns the page to the previous and the next note", () => {
        const wrapper = render({
            previous: { id: 4, title: "Chapitre un" },
            next: { id: 6, title: "Chapitre trois" },
        });

        expect(wrapper.find("[data-read-previous]").attributes("href")).toBe(
            "/notes/4/read",
        );
        expect(wrapper.find("[data-read-next]").attributes("href")).toBe(
            "/notes/6/read",
        );
    });

    /** The arrow keys do the same, except when typing somewhere. */
    it("turns the page with the arrow keys", () => {
        const assign = vi.fn();
        vi.spyOn(window, "location", "get").mockReturnValue({
            ...window.location,
            assign,
        });

        render({ next: { id: 6, title: "Chapitre trois" } });

        window.dispatchEvent(
            new KeyboardEvent("keydown", { key: "ArrowRight" }),
        );

        expect(assign).toHaveBeenCalledWith("/notes/6/read");
    });

    /** Someone else's note is read, it is not edited. */
    it("offers to edit only one's own note", () => {
        expect(
            render({ canEdit: true }).find("[data-read-edit]").exists(),
        ).toBe(true);
        expect(
            render({ canEdit: false }).find("[data-read-edit]").exists(),
        ).toBe(false);
    });
});

describe("l'espace du lecteur", () => {
    /** No back-office menu, no bar: the tree and the text. */
    it("carries its own tree, opened on the note being read", () => {
        const wrapper = render();
        const sidebar = wrapper.find("[data-reader-sidebar]");

        expect(sidebar.exists()).toBe(true);
        expect(
            sidebar.find('[data-note-row="5"]').attributes("aria-selected"),
        ).toBe("true");
        expect(sidebar.find('[data-note-row="5"] a').attributes("href")).toBe(
            "/notes/5/read",
        );
        // In reading, nothing is written: no plus, no drag.
        expect(sidebar.find("[data-folder-row]").attributes("draggable")).toBe(
            "false",
        );
    });

    /** On a phone, the tree lives in a drawer behind "Sommaire". */
    it("opens the tree in a drawer on a phone", async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: false });

        const wrapper = render();
        expect(wrapper.find("[data-reader-drawer]").exists()).toBe(false);

        await wrapper.find("[data-reader-nav-toggle]").trigger("click");
        expect(wrapper.find("[data-reader-drawer]").exists()).toBe(true);

        window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
        await wrapper.vm.$nextTick();
        expect(wrapper.find("[data-reader-drawer]").exists()).toBe(false);
    });

    it("folds the tree away on a computer, and remembers it", async () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true });
        window.localStorage.removeItem("aurora.notes.reader.sidebar");

        const wrapper = render();
        await wrapper.find("[data-reader-nav-toggle]").trigger("click");

        expect(wrapper.find("[data-reader-sidebar]").exists()).toBe(false);
        expect(window.localStorage.getItem("aurora.notes.reader.sidebar")).toBe(
            "0",
        );
        window.localStorage.removeItem("aurora.notes.reader.sidebar");
    });
});

describe("le retour", () => {
    /** A back link, not the site name, at the top left. */
    it("goes back to editing one's own note", () => {
        const wrapper = render({ canEdit: true });

        expect(wrapper.find("[data-reader-back]").attributes("href")).toBe(
            "/notes/5",
        );
    });

    it("goes back to the library from somebody else's note", () => {
        const wrapper = render({ canEdit: false });

        expect(wrapper.find("[data-reader-back]").attributes("href")).toBe(
            "/notes",
        );
    });
});

describe("la recherche du lecteur", () => {
    /** As in the panel: a word of the text finds the note, not only its title. */
    it("finds a note by a word of its text", async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, ids: [9] }),
        });

        const wrapper = render({ searchPath: "/notes/search" });
        const input = wrapper.find("[data-reader-sidebar] input");

        await input.setValue("facture");
        await new Promise((resolve) => setTimeout(resolve, 350));
        await wrapper.vm.$nextTick();

        expect(String(global.fetch.mock.calls[0][0])).toContain(
            "/notes/search?q=facture",
        );
        expect(
            wrapper.find('[data-reader-sidebar] [data-note-row="9"]').exists(),
        ).toBe(true);
        expect(
            wrapper.find('[data-reader-sidebar] [data-note-row="5"]').exists(),
        ).toBe(false);
    });
});

describe("la lecture publique d'un espace", () => {
    /**
     * Without an account, nothing leads to the back-office: the space name
     * replaces the back link, a folder reads without a link, and there is
     * neither editing nor star.
     */
    it("names the space and leads nowhere into the back office", () => {
        const wrapper = render({
            publicTitle: "Guide public",
            libraryPath: "/p/guide",
            folderShowPath: "",
            backPath: "",
            canEdit: false,
            favoritePath: "",
            breadcrumb: [{ id: 1, name: "Micro-entreprise" }],
        });

        const title = wrapper.find("[data-reader-public-title]");
        expect(title.text()).toBe("Guide public");
        expect(title.attributes("href")).toBe("/p/guide");
        expect(wrapper.find("[data-reader-back]").exists()).toBe(false);
        expect(wrapper.find("[data-read-crumb]").element.tagName).toBe("SPAN");
        expect(wrapper.find("[data-read-edit]").exists()).toBe(false);
        expect(wrapper.find("[data-read-favorite]").exists()).toBe(false);
    });
});

describe("l'espace de la note, dans l'arbre du lecteur", () => {
    it("names one's own space even when it is the only one", () => {
        const wrapper = render({
            treeSpaces: [{ id: 1, name: null, personal: true, canWrite: true }],
        });

        const headers = wrapper.findAll("[data-reader-space]");
        expect(headers.length).toBeGreaterThan(0);
        expect(headers[0].text()).toContain("notes.markdown.spaces.my_space");
    });

    /** Public reading receives no spaces, and shows none. */
    it("shows no space header on the public page", () => {
        const wrapper = render({
            treeSpaces: [],
            publicTitle: "Guide",
            folderShowPath: "",
        });

        expect(wrapper.find("[data-reader-space]").exists()).toBe(false);
    });
});
