import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;
window.matchMedia = vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    addListener: vi.fn(),
    removeListener: vi.fn(),
    dispatchEvent: vi.fn(),
}));

const MarkdownNotesApp = (await import("./MarkdownNotesApp.vue")).default;

const i18n = createTestI18n();

const PATHS = [
    "listPath",
    "showPath",
    "createPath",
    "updatePath",
    "deletePath",
    "movePath",
    "reorderPath",
    "backlinksPath",
    "unlinkedMentionsPath",
    "graphPath",
    "searchPath",
    "tagsListPath",
    "tagsRenamePath",
    "tagsMergePath",
    "tagsDeletePath",
    "sharesListPath",
    "sharesPreviewPath",
    "sharesCreatePath",
    "sharesRevokePath",
    "imageUploadPath",
].reduce((all, name) => ({ ...all, [name]: `/notes/${name}` }), {});

const NOTES = [{ id: 1, title: "Journal", parentId: null, tags: [] }];

const mounted = [];

function render(props = {}) {
    const wrapper = mount(MarkdownNotesApp, {
        props: { ...PATHS, notes: NOTES, ...props },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            notes: NOTES,
            note: NOTES[0],
            tags: [],
        }),
    });
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    vi.restoreAllMocks();
});

describe("the notes page, once its tree moved to the menu", () => {
    /**
     * A hundred and thirty-nine lines of template came out - the widest aside
     * of the six. Vue resolves template references at render, so a name left
     * behind is invisible to the linter and to the bundler.
     */
    it("still mounts, with no reference left behind", () => {
        const wrapper = render();

        expect(wrapper.html()).toBeTruthy();
        expect(wrapper.find("aside").exists()).toBe(false);
    });

    it("answers the panel when it asks to open a note", async () => {
        render();
        await flushPromises();

        expect(askPage("notes:select", { args: [1] })).toBe(true);
    });

    it("answers the panel when it asks to create one", async () => {
        render();
        await flushPromises();

        expect(askPage("notes:create", { args: [null] })).toBe(true);
    });

    /** Nothing must answer once the editor is gone. */
    it("stops answering after it unmounts", async () => {
        const wrapper = render();
        await flushPromises();
        wrapper.unmount();
        mounted.pop();

        expect(askPage("notes:select", { args: [1] })).toBe(false);
    });
});

describe("what the page tells the panel", () => {
    /**
     * The bug the reader hit: a note created in the editor did not reach the
     * tree until the page was reloaded. The panel fetches once on arrival; from
     * then on this is the only thing that keeps it true.
     */
    it("announces its list so the tree can follow", async () => {
        const heard = vi.fn();
        const stop = onPageNotice("notes:changed", heard);

        render();
        await flushPromises();

        expect(heard).toHaveBeenCalled();
        expect(heard.mock.calls.at(-1)[0].notes).toBeInstanceOf(Array);
        stop();
    });

    /** Every row action the tree used to do itself is answered here. */
    it("answers every intent the panel can send", async () => {
        render();
        await flushPromises();

        // Plausible arguments, because the page runs the real handler: an
        // empty list would have them dereferencing an event that is not there.
        const note = NOTES[0];
        const event = {
            preventDefault: () => {},
            stopPropagation: () => {},
            currentTarget: { contains: () => false },
            relatedTarget: null,
            dataTransfer: {
                types: [],
                setData: () => {},
                getData: () => "",
                effectAllowed: "",
                dropEffect: "",
            },
        };

        for (const [intent, args] of [
            ["select", [note.id]],
            ["create", [null]],
            ["delete", [note]],
            ["drag-start", [note, event]],
            ["drag-end", []],
            ["drag-over", [note, event]],
            ["drag-leave", [note, event]],
            ["drop", [note, event]],
        ]) {
            expect(askPage(`notes:${intent}`, { args })).toBe(true);
        }
    });
});

describe("deleting a note the panel asked to delete", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });

    /**
     * The reader saw the modal appear and do nothing. The whole round trip is
     * pinned here because it crosses three things at once: the bridge, a modal
     * that teleports out of the component, and the delete call itself.
     */
    it("opens the confirmation and deletes on confirm", async () => {
        render();
        await flushPromises();

        expect(askPage("notes:delete", { args: [NOTES[0]] })).toBe(true);
        await flushPromises();

        // The confirmation names the note through a translation parameter the
        // test i18n does not fill, so the key is what shows.
        expect(document.body.textContent).toContain("notes.markdown.delete");

        const confirm = [...document.body.querySelectorAll("button")].find(
            (b) => /delete|confirm/.test(b.textContent),
        );
        expect(confirm, "the confirmation button is on screen").toBeTruthy();

        confirm.click();
        await flushPromises();

        const called = global.fetch.mock.calls
            .map(([url]) => String(url))
            .some((url) => url.includes("deletePath"));
        expect(called, "the delete endpoint was called").toBe(true);
    });
});
