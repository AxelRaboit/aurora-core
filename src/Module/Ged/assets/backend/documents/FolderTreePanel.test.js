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

const mounted = [];

async function render(url = "/backend/ged/tags") {
    window.history.replaceState({}, "", url);
    const wrapper = mount(FolderTreePanel, { global: { plugins: [i18n] } });
    mounted.push(wrapper);
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

// `AppModal` teleports to `body`, so an unmounted panel would leave its modal
// behind for the next test to trip over.
afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
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

describe("the panel on an installation with no folder yet", () => {
    beforeEach(() => answerWith({ success: true, folders: [] }));

    /**
     * The state every new installation starts in, and the one the panel got
     * wrong: the modals lived in the shell's default slot, which is exactly the
     * slot an empty list does not render. The "+" set a flag nothing was
     * listening to.
     */
    it("opens the create modal from the + button", async () => {
        const wrapper = await render();

        const plus = wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "backend.ged.documents.new_folder",
            );
        expect(plus).toBeTruthy();

        await plus.trigger("click");

        // The modal teleports to `body`, so it is never inside the wrapper.
        expect(document.body.textContent).toContain(
            "backend.ged.documents.folder_name",
        );
    });

    /**
     * The two scopes are not folders, so having none must not take them away.
     * The aside showed them on an empty install and so must this.
     */
    it("still offers the two scopes", async () => {
        const hrefs = (await render())
            .findAll("a")
            .map((a) => a.attributes("href"));

        expect(hrefs).toEqual([
            "/backend/ged/documents",
            "/backend/ged/documents?rootOnly=1",
        ]);
    });
});

describe("dragging a folder onto another", () => {
    const FOLDER_MIME = "application/x-aurora-document-folder";

    /** A row 100 px tall, so a clientY reads straight as a percentage. */
    function rowAt(wrapper, index) {
        const row = wrapper.findAll("[data-folder-depth]")[index];
        row.element.getBoundingClientRect = () => ({ top: 0, height: 100 });

        return row;
    }

    const dragging = (id) => ({
        types: [FOLDER_MIME],
        getData: (type) => (type === FOLDER_MIME ? String(id) : ""),
        effectAllowed: "",
        dropEffect: "",
    });

    const posted = () =>
        global.fetch.mock.calls
            .map(([url, init]) => ({ url: String(url), body: init?.body }))
            .filter((call) => call.body);

    /**
     * The middle fifth of the row. This is the gesture the panel already had -
     * the one the dedicated folders page called "into".
     */
    it("reparents when dropped on the middle", async () => {
        const wrapper = await render();

        // Row 0 is "Contrats"; dropping "Factures" on its middle nests it.
        await rowAt(wrapper, 0).trigger("drop", {
            clientY: 50,
            dataTransfer: dragging(3),
        });

        const call = posted().at(-1);
        expect(call.url).toBe("/backend/ged/folders/3/move");
        expect(JSON.parse(call.body)).toEqual({ parentId: 1 });
    });

    /**
     * The top and bottom bands, which the panel did not have. Without them the
     * folders page cannot be deleted: ordering would have nowhere to live.
     */
    it("reorders when dropped on the top edge", async () => {
        const wrapper = await render();

        // Row 2 is "Factures", a root folder; dropping "Contrats" above it
        // reorders the two roots rather than nesting one in the other.
        await rowAt(wrapper, 2).trigger("drop", {
            clientY: 10,
            dataTransfer: dragging(1),
        });

        const call = posted().at(-1);
        expect(call.url).toBe("/backend/ged/folders/reorder");
        expect(JSON.parse(call.body)).toEqual({ ids: [1, 3] });
    });

    it("reorders the other way on the bottom edge", async () => {
        const wrapper = await render();

        await rowAt(wrapper, 2).trigger("drop", {
            clientY: 90,
            dataTransfer: dragging(1),
        });

        expect(JSON.parse(posted().at(-1).body)).toEqual({ ids: [3, 1] });
    });

    /** A document has no rank among folders: only the middle applies to it. */
    it("files a document into the folder wherever it lands on the row", async () => {
        const wrapper = await render();
        const DOC_MIME = "application/x-aurora-document";

        await rowAt(wrapper, 2).trigger("drop", {
            clientY: 5,
            dataTransfer: {
                types: [DOC_MIME],
                getData: (t) => (t === DOC_MIME ? "42" : ""),
            },
        });

        const call = posted().at(-1);
        expect(call.url).toBe("/backend/ged/documents/bulk-move");
        expect(JSON.parse(call.body)).toEqual({ ids: [42], folderId: 3 });
    });
});

describe("the order the reader dragged into place", () => {
    /**
     * The tree builder sorts alphabetically unless told otherwise, so the
     * order came back sorted by name on the very next render: the server had
     * stored it, the screen ignored it, and the drag looked like it had done
     * nothing at all.
     */
    it("follows position, not the alphabet", async () => {
        answerWith({
            success: true,
            folders: [
                {
                    id: 1,
                    name: "Zèbres",
                    parentId: null,
                    position: 0,
                    documentCount: 0,
                },
                {
                    id: 2,
                    name: "Abeilles",
                    parentId: null,
                    position: 1,
                    documentCount: 0,
                },
            ],
        });

        const names = (await render())
            .findAll("[data-folder-depth] a")
            .map((a) => a.text());

        expect(names).toEqual(["Zèbres", "Abeilles"]);
    });

    it("falls back to the name when two share a position", async () => {
        answerWith({
            success: true,
            folders: [
                {
                    id: 1,
                    name: "Zèbres",
                    parentId: null,
                    position: 0,
                    documentCount: 0,
                },
                {
                    id: 2,
                    name: "Abeilles",
                    parentId: null,
                    position: 0,
                    documentCount: 0,
                },
            ],
        });

        const names = (await render())
            .findAll("[data-folder-depth] a")
            .map((a) => a.text());

        expect(names).toEqual(["Abeilles", "Zèbres"]);
    });
});

describe("dragging a folder out of the branch it sits in", () => {
    const FOLDER_MIME = "application/x-aurora-document-folder";

    function rowAt(wrapper, index) {
        const row = wrapper.findAll("[data-folder-depth]")[index];
        row.element.getBoundingClientRect = () => ({ top: 0, height: 100 });

        return row;
    }

    const dragging = (id) => ({
        types: [FOLDER_MIME],
        getData: (type) => (type === FOLDER_MIME ? String(id) : ""),
        effectAllowed: "",
        dropEffect: "",
    });

    const posted = () =>
        global.fetch.mock.calls
            .map(([url, init]) => ({ url: String(url), body: init?.body }))
            .filter((call) => call.body);

    /**
     * The bug that made a nested folder impossible to get back out. `reorder`
     * assigns positions and never touches a parent, so dropping "2026" beside
     * a root folder renumbered it into the roots' order while leaving it inside
     * "Contrats" - it looked like it had jumped somewhere nobody asked for, and
     * the only way out was gone.
     */
    it("changes the parent before writing the new order", async () => {
        const wrapper = await render();

        // "2026" sits under "Contrats"; drop it above the root "Factures".
        await rowAt(wrapper, 2).trigger("drop", {
            clientY: 10,
            dataTransfer: dragging(2),
        });
        // Two requests in sequence: the second only starts once the first has
        // resolved, so one tick is not enough.
        await flushPromises();
        await flushPromises();

        const calls = posted().slice(-2);
        expect(calls[0].url).toBe("/backend/ged/folders/2/move");
        expect(JSON.parse(calls[0].body)).toEqual({ parentId: null });
        expect(calls[1].url).toBe("/backend/ged/folders/reorder");
    });

    /**
     * Filing a folder inside its own child points the two at each other, and
     * every screen that builds a tree stops drawing them. The server refuses
     * it; the panel does not even offer it.
     */
    it("refuses to drop a folder into its own child", async () => {
        const wrapper = await render();
        const before = posted().length;

        await rowAt(wrapper, 0).trigger("dragstart", {
            dataTransfer: { setData: () => {}, effectAllowed: "" },
        });
        await rowAt(wrapper, 1).trigger("drop", {
            clientY: 50,
            dataTransfer: dragging(1),
        });

        expect(posted()).toHaveLength(before);
    });
});
