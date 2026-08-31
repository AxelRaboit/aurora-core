import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

const FolderTreePanel = (await import("./FolderTreePanel.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    { id: 1, name: "Contrats", parentId: null, documentCount: 3 },
    { id: 2, name: "2026", parentId: 1, documentCount: 7 },
    { id: 3, name: "Factures", parentId: null, documentCount: 0 },
];

function answerWith(payload, ok = true) {
    global.fetch = vi.fn().mockResolvedValue({
        ok,
        status: ok ? 200 : 500,
        json: async () => payload,
    });
}

async function render(url = "http://localhost/backend/ged/tags") {
    window.history.replaceState({}, "", url.replace("http://localhost", ""));
    const wrapper = mount(FolderTreePanel, { global: { plugins: [i18n] } });
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    localStorage.clear();
    answerWith({ success: true, folders: FOLDERS });
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe("the GED folder tree panel", () => {
    it("fetches its own tree, because the menu hands it no props", async () => {
        await render();

        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/ged/documents/folders",
            expect.anything(),
        );
    });

    /**
     * The reason the rows are links rather than click handlers: the panel is
     * mounted on every GED page, including the ones with no document listing
     * to filter. A handler would only have worked on the documents page.
     */
    it("points every row at a real folder address", async () => {
        const hrefs = (await render())
            .findAll("a")
            .map((a) => a.attributes("href"));

        expect(hrefs).toEqual([
            "/backend/ged/documents?folderId=1",
            "/backend/ged/documents?folderId=2",
            "/backend/ged/documents?folderId=3",
        ]);
    });

    it("nests a child under its parent", async () => {
        const rows = (await render()).findAll("[data-folder-depth]");

        expect(rows.map((r) => r.attributes("data-folder-depth"))).toEqual([
            "0",
            "1",
            "0",
        ]);
        expect(rows[1].text()).toContain("2026");
    });

    it("hides a branch the reader folded, and keeps folding out of navigation", async () => {
        const wrapper = await render();

        await wrapper.find("button").trigger("click");

        const names = wrapper.findAll("a").map((a) => a.text());
        expect(names.some((n) => n.includes("2026"))).toBe(false);
        expect(names.some((n) => n.includes("Contrats"))).toBe(true);
    });

    /**
     * The fold state is the documents page's own localStorage key. Sharing it
     * is the point: the same folders are folded in the menu and in the page's
     * sidebar, so the two never show a different tree.
     */
    it("reads the fold state the documents page persisted", async () => {
        localStorage.setItem(
            "aurora-ged-collapsed-folders",
            JSON.stringify([1]),
        );

        const names = (await render()).findAll("a").map((a) => a.text());

        expect(names.some((n) => n.includes("2026"))).toBe(false);
    });

    /**
     * The one page that already has this tree, with creation, drag-and-drop and
     * the "Tous les documents" / "Racine" filters on top. Two of them thirty
     * centimetres apart would be worse than one.
     */
    it("steps aside on the page that owns the tree", async () => {
        const wrapper = await render("http://localhost/backend/ged/documents");

        expect(wrapper.text()).toBe("");
        expect(global.fetch).not.toHaveBeenCalled();
    });

    /** Exactly that page, not everything under it: a document has no tree. */
    it("still draws on a document's own page", async () => {
        const wrapper = await render(
            "http://localhost/backend/ged/documents/42",
        );

        expect(wrapper.findAll("a")).toHaveLength(3);
    });

    /**
     * A failed auxiliary GET must not shout. The navigation above the panel is
     * unaffected, and a toast on every GED page would be louder than what it
     * reports.
     */
    it("disappears rather than complaining when the fetch fails", async () => {
        answerWith(null, false);

        const wrapper = await render();

        expect(wrapper.find("a").exists()).toBe(false);
        expect(wrapper.text()).toBe("");
    });
});
