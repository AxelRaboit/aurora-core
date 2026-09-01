import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest } from "@/shared/nav/modulePanelBridge.js";

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

const noteLinks = (wrapper) => wrapper.findAll("[data-note-depth] a");

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
        const depths = (await render())
            .findAll("[data-note-depth]")
            .map((r) => r.attributes("data-note-depth"));

        expect(depths).toEqual(["0", "1", "0"]);
    });

    /** The editor is mounted, so it takes the click and swaps in place. */
    it("lets the page open the note instead of navigating", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:note", handler));

        await noteLinks(await render())[2].trigger("click");

        expect(handler).toHaveBeenCalledWith({ id: 3 });
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
