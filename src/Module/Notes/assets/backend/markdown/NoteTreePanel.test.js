import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest, tellPanels } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;

const NoteTreePanel = (await import("./NoteTreePanel.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    { id: 1, name: "Journal", parentId: null, noteCount: 1, folderCount: 1 },
    { id: 2, name: "Lundi", parentId: 1, noteCount: 0, folderCount: 0 },
    { id: 3, name: "Recettes", parentId: null, noteCount: 2, folderCount: 0 },
];

const NOTES = [
    { id: 11, title: "Journal de bord", folderId: 1, tags: ["perso"] },
    { id: 12, title: "Tarte", folderId: 3, tags: ["cuisine"] },
];

/**
 * Le panneau demande deux listes, et une recherche quand on tape : la réponse
 * dépend donc de l'adresse, pas du rang de l'appel.
 */
function answerWith({
    folders = FOLDERS,
    notes = NOTES,
    ids = [],
    ok = true,
} = {}) {
    global.fetch = vi.fn().mockImplementation(async (url) => {
        const path = String(url);
        const payload = path.includes("/folders")
            ? { success: true, folders }
            : path.includes("/search")
              ? { success: true, ids }
              : { success: true, notes };

        return {
            ok,
            status: ok ? 200 : 500,
            json: async () => (ok ? payload : null),
        };
    });
}

const mounted = [];
const stops = [];

async function render(url = "/backend/notes/markdown") {
    window.history.replaceState({}, "", url);
    const wrapper = mount(NoteTreePanel, { global: { plugins: [i18n] } });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

/** Les lignes de dossiers, sans la ligne « Tous les documents » en tête. */
const folderLinks = (wrapper) =>
    wrapper
        .findAll("a")
        .filter((a) => a.attributes("href")?.includes("/folder/"));

beforeEach(() => answerWith());

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    while (stops.length) stops.pop()();
    vi.restoreAllMocks();
});

describe("the folders panel", () => {
    it("fetches its own lists, because the menu hands it no props", async () => {
        await render();

        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/notes/markdown/folders",
            expect.anything(),
        );
        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/notes/markdown/list",
            expect.anything(),
        );
    });

    /**
     * A folder is a page now, so a row is a real address: it can be sent to
     * somebody and opened in a new tab.
     */
    it("points every row at the folder's own address", async () => {
        const hrefs = folderLinks(await render()).map((a) =>
            a.attributes("href"),
        );

        expect(hrefs).toEqual([
            "/backend/notes/markdown/folder/1",
            "/backend/notes/markdown/folder/2",
            "/backend/notes/markdown/folder/3",
        ]);
    });

    it("nests a folder under its parent", async () => {
        // The indent is on the row, not on the link inside it: the row is the
        // drop target and the draggable handle, the link is only the name.
        const indents = (await render())
            .findAll("[data-folder-row]")
            .map((row) => row.attributes("style") ?? "");

        expect(indents[0]).not.toEqual(indents[1]);
        expect(indents[0]).toEqual(indents[2]);
    });

    /** The library is mounted, so it takes the click and swaps in place. */
    it("lets the page open the folder instead of navigating", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:open-folder", handler));

        await folderLinks(await render())[2].trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [3] });
    });

    /** Making a note is naming it and putting the cursor in it - the page's job. */
    it("asks the page to create rather than doing it itself", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:create", handler));

        const wrapper = await render();
        const plus = wrapper
            .findAll("button")
            .find(
                (b) => b.attributes("title") === "notes.markdown.create_root",
            );
        await plus.trigger("click");

        expect(handler).toHaveBeenCalled();
    });

    it("asks the page for a new folder too", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:create-folder", handler));

        const wrapper = await render();
        const button = wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") === "notes.markdown.folders.create",
            );
        await button.trigger("click");

        expect(handler).toHaveBeenCalled();
    });

    it("filters the tree on what the reader typed", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("recett");
        await flushPromises();

        expect(folderLinks(wrapper).map((a) => a.text())).toEqual([
            "Recettes2",
        ]);
    });

    /**
     * The one search that reaches the whole notebook.
     *
     * The library filters the folder it shows, which is what an explorer
     * does; finding a note whose folder you have forgotten is this field's
     * job, and the matched notes are listed flat beneath the folders.
     */
    it("lists the notes that match, across the notebook", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("tarte");
        await flushPromises();

        const noteLinks = wrapper
            .findAll("a")
            .filter((a) => /\/markdown\/\d+$/.test(a.attributes("href") ?? ""));

        expect(noteLinks.map((a) => a.text())).toEqual(["TarteRecettes"]);
    });

    it("disappears rather than complaining when the fetch fails", async () => {
        answerWith({ ok: false });

        const wrapper = await render();

        expect(wrapper.text()).toBe("");
    });
});

describe("what the panel kept from the aside", () => {
    it("offers the row actions the tree used to have", async () => {
        const titles = (await render())
            .findAll("button")
            .map((b) => b.attributes("title"))
            .filter(Boolean);

        expect(titles.some((t) => t.includes("create_in_folder"))).toBe(true);
        expect(titles.some((t) => t.includes("folders.delete"))).toBe(true);
    });

    it("lets a row be dragged", async () => {
        const row = (await render()).find("[data-folder-row]");

        expect(row.attributes("draggable")).toBe("true");
    });

    /**
     * The buttons sit beside the link, never inside it. Nested interactive
     * content is invalid HTML, and it cost a page reload: the buttons stop
     * the click, so the row never got to cancel the link's navigation.
     */
    it("keeps its buttons out of the link", async () => {
        const wrapper = await render();

        expect(wrapper.findAll("a button")).toHaveLength(0);
    });

    /**
     * The bug the reader hit: something created in the editor did not show
     * up until the page was reloaded, because the panel had fetched its list
     * once on arrival and nothing ever told it otherwise.
     */
    it("takes the page's word for the list when it changes", async () => {
        const wrapper = await render();
        expect(folderLinks(wrapper)).toHaveLength(3);

        tellPanels("notes:changed", {
            folders: [...FOLDERS, { id: 4, name: "Neuf", parentId: null }],
        });
        await flushPromises();

        expect(folderLinks(wrapper).map((a) => a.text())).toContain("Neuf");
    });
});
