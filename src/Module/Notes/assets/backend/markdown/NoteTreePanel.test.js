import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest, tellPanels } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;

const NoteTreePanel = (await import("./NoteTreePanel.vue")).default;

const i18n = createTestI18n();

const NOTES = [
    { id: 1, title: "Journal", parentId: null, tags: ["perso"] },
    { id: 2, title: "Lundi", parentId: 1, tags: [] },
    { id: 3, title: "Recettes", parentId: null, tags: ["cuisine"] },
];

function answerWith(payload, ok = true) {
    global.fetch = vi.fn().mockResolvedValue({
        ok,
        status: ok ? 200 : 500,
        json: async () => payload,
    });
}

const mounted = [];
const stops = [];

async function render(url = "/backend/notes/markdown/3") {
    window.history.replaceState({}, "", url);
    const wrapper = mount(NoteTreePanel, { global: { plugins: [i18n] } });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

// `NoteTreeItem` draws the rows now, and each is an anchor.
const noteLinks = (wrapper) => wrapper.findAll("a");

beforeEach(() => answerWith({ success: true, notes: NOTES }));

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    while (stops.length) stops.pop()();
    vi.restoreAllMocks();
});

describe("the notes panel", () => {
    it("fetches its own list, because the menu hands it no props", async () => {
        await render();

        expect(global.fetch).toHaveBeenCalledWith(
            "/backend/notes/markdown/list",
            expect.anything(),
        );
    });

    /**
     * A note is a page now, so a row is a real address: it can be sent to
     * somebody and opened in a new tab.
     */
    it("points every row at the note's own address", async () => {
        const hrefs = noteLinks(await render()).map((a) =>
            a.attributes("href"),
        );

        expect(hrefs).toEqual([
            "/backend/notes/markdown/1",
            "/backend/notes/markdown/2",
            "/backend/notes/markdown/3",
        ]);
    });

    it("nests a note under its parent", async () => {
        // The indent is on the row, not on the link inside it: the row is the
        // drop target and the draggable handle, the link is only the title.
        const indents = (await render())
            .findAll("[data-note-row]")
            .map((row) => row.attributes("style") ?? "");

        expect(indents[0]).not.toEqual(indents[1]);
        expect(indents[0]).toEqual(indents[2]);
    });

    /** The editor is mounted, so it takes the click and swaps in place. */
    it("lets the page open the note instead of navigating", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:select", handler));

        await noteLinks(await render())[2].trigger("click");

        // Arguments travel as they are, so the page can call the very
        // handler the aside used to call.
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

    it("filters the tree on what the reader typed", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("recett");
        await flushPromises();

        const titles = noteLinks(wrapper).map((a) => a.text());
        expect(titles).toEqual(["Recettes"]);
    });

    it("disappears rather than complaining when the fetch fails", async () => {
        answerWith(null, false);

        const wrapper = await render();

        expect(wrapper.text()).toBe("");
    });
});

describe("what the panel kept from the aside", () => {
    /**
     * The first pass rebuilt the row by hand and lost these without a word: a
     * hand-written `v-for` only has what you remember to give it. The rows are
     * `NoteTreeItem` now, the aside's own component, so there is nothing to
     * remember.
     */
    it("offers the row actions the tree used to have", async () => {
        const titles = (await render())
            .findAll("button")
            .map((b) => b.attributes("title"))
            .filter(Boolean);

        expect(titles.some((t) => t.includes("create_child"))).toBe(true);
        expect(titles.some((t) => t.includes("delete"))).toBe(true);
    });

    it("lets a row be dragged", async () => {
        const row = (await render()).find("[data-note-row]");

        expect(row.attributes("draggable")).toBe("true");
    });

    /**
     * The buttons sit beside the link, never inside it. Nested interactive
     * content is invalid HTML, and it cost a page reload: the buttons stop the
     * click, so the row never got to cancel the link's navigation and pressing
     * "new child note" followed the href.
     */
    it("keeps its buttons out of the link", async () => {
        const wrapper = await render();

        expect(wrapper.findAll("a button")).toHaveLength(0);
    });

    /**
     * The bug the reader hit: a note created in the editor did not show up
     * until the page was reloaded, because the panel had fetched its list once
     * on arrival and nothing ever told it otherwise.
     */
    it("takes the page's word for the list when it changes", async () => {
        const wrapper = await render();
        expect(noteLinks(wrapper)).toHaveLength(3);

        tellPanels("notes:changed", {
            notes: [
                ...NOTES,
                { id: 4, title: "Neuve", parentId: null, tags: [] },
            ],
        });
        await flushPromises();

        expect(noteLinks(wrapper).map((a) => a.text())).toContain("Neuve");
    });
});
