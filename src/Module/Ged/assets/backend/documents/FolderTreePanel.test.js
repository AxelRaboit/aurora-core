import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest } from "@/shared/nav/modulePanelBridge.js";

// `usePrivileges` reads these once, at module load, so they have to be in place
// before the panel is imported.
window.__isAdmin__ = true;

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

async function render(url = "/backend/ged/tags") {
    window.history.replaceState({}, "", url);
    const wrapper = mount(FolderTreePanel, { global: { plugins: [i18n] } });
    await flushPromises();

    return wrapper;
}

const folderLinks = (wrapper) =>
    wrapper.findAll("[data-folder-depth] a").map((a) => a);

const stops = [];

beforeEach(() => {
    localStorage.clear();
    answerWith({ success: true, folders: FOLDERS });
});

afterEach(() => {
    while (stops.length) stops.pop()();
    vi.restoreAllMocks();
});

describe("the GED folder panel", () => {
    it("fetches its own tree, because the menu hands it no props", async () => {
        await render();

        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/ged/documents/folders",
            expect.anything(),
        );
    });

    /**
     * Real addresses, not click handlers: the panel is mounted on every GED
     * page, and middle-click and "open in a new tab" have to keep working.
     */
    it("points every row at a real address", async () => {
        const wrapper = await render();
        const hrefs = wrapper.findAll("a").map((a) => a.attributes("href"));

        expect(hrefs).toEqual([
            "/backend/ged/documents",
            "/backend/ged/documents?rootOnly=1",
            "/backend/ged/documents?folderId=1",
            "/backend/ged/documents?folderId=2",
            "/backend/ged/documents?folderId=3",
        ]);
    });

    /**
     * The aside is gone, so this page needs the panel like every other. It was
     * briefly the one page the panel skipped, back when the aside still drew
     * the same tree thirty centimetres away.
     */
    it("draws on the documents page too, now that the aside is gone", async () => {
        const wrapper = await render("/backend/ged/documents");

        expect(folderLinks(wrapper)).toHaveLength(3);
    });

    it("nests a child under its parent", async () => {
        const rows = (await render()).findAll("[data-folder-depth]");

        expect(rows.map((r) => r.attributes("data-folder-depth"))).toEqual([
            "0",
            "1",
            "0",
        ]);
    });

    it("hides a branch the reader folded, and keeps folding out of navigation", async () => {
        const wrapper = await render();

        await wrapper.find("[data-folder-depth] button").trigger("click");

        const names = folderLinks(wrapper).map((a) => a.text());
        expect(names.some((n) => n.includes("2026"))).toBe(false);
        expect(names.some((n) => n.includes("Contrats"))).toBe(true);
    });

    it("reads the fold state the documents page persisted", async () => {
        localStorage.setItem(
            "aurora-ged-collapsed-folders",
            JSON.stringify([1]),
        );

        const names = folderLinks(await render()).map((a) => a.text());

        expect(names.some((n) => n.includes("2026"))).toBe(false);
    });

    /**
     * The bridge, which is the reason a link works on the documents page
     * without reloading it: the page answers, so the browser is left alone.
     */
    it("lets the page take the click instead of navigating", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("ged:folder", handler));

        const wrapper = await render("/backend/ged/documents");
        await folderLinks(wrapper)[2].trigger("click");

        expect(handler).toHaveBeenCalledWith({ folderId: 3, scope: "all" });
    });

    it("marks the folder the page moved to", async () => {
        stops.push(onPanelRequest("ged:folder", () => {}));

        const wrapper = await render("/backend/ged/documents");
        await folderLinks(wrapper)[2].trigger("click");

        expect(folderLinks(wrapper)[2].attributes("class")).toContain(
            "bg-lime-600/15",
        );
    });

    /** Nobody listening - the reader is on the tags page - so the link is left alone. */
    it("leaves the link alone when no page answers", async () => {
        const wrapper = await render("/backend/ged/tags");

        await folderLinks(wrapper)[0].trigger("click");

        // `hover:bg-lime-600/10` is on every idle row, so the active
        // background is the class to look for, not the colour name.
        expect(folderLinks(wrapper)[0].attributes("class")).not.toContain(
            "bg-lime-600/15",
        );
    });

    /**
     * The aside kept a shortcut list above its tree. Somebody who starred nine
     * folders out of ninety did it to stop scrolling; dropping it in the move
     * would have been a quiet loss.
     */
    it("keeps the favourites shortcut the aside had", async () => {
        localStorage.setItem(
            "aurora-ged-favourite-folders",
            JSON.stringify([3]),
        );

        const wrapper = await render();
        const hrefs = wrapper.findAll("a").map((a) => a.attributes("href"));

        // Once as a favourite, once in the tree at its own depth. The
        // shortcut sits above the two scopes, where the aside kept it.
        expect(
            hrefs.filter((h) => h === "/backend/ged/documents?folderId=3"),
        ).toHaveLength(2);
        expect(hrefs[0]).toBe("/backend/ged/documents?folderId=3");
        expect(hrefs[1]).toBe("/backend/ged/documents");
    });

    it("offers the writes the aside used to own", async () => {
        const wrapper = await render();

        expect(
            wrapper.find("[data-folder-depth]").attributes("draggable"),
        ).toBe("true");
        const titles = wrapper
            .findAll("button")
            .map((b) => b.attributes("title"));
        expect(titles).toContain("backend.ged.documents.new_folder");
        expect(titles).toContain("backend.ged.documents.edit_folder");
    });

    /**
     * A failed auxiliary GET must not shout: the navigation above the panel is
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
