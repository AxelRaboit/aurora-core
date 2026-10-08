import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { askPage, onPageNotice } from "@/shared/nav/modulePanelBridge.js";

window.__isAdmin__ = true;

/**
 * Set again before each case, and not once and for all.
 *
 * `vi.restoreAllMocks()` gives a `vi.fn()` back its empty implementation: set
 * when the module loaded, `matchMedia` stopped answering from the second case
 * on, and the page then mounted without knowing whether it is on a phone.
 */
function installMatchMedia() {
    window.matchMedia = vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
    }));
}

installMatchMedia();

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
    "exportPath",
    "exportOnePath",
    "importPath",
    "searchPath",
    "tagsListPath",
    "tagsRenamePath",
    "tagsMergePath",
    "tagsDeletePath",
    "sharesListPath",
    "sharesPreviewPath",
    "sharesCreatePath",
    "sharesRevokePath",
    "liveBeatPath",
    "peopleListPath",
    "peopleSetPath",
    "peopleRemovePath",
    "imageUploadPath",
].reduce((all, name) => ({ ...all, [name]: `/notes/${name}` }), {});

PATHS.libraryPath = "/notes/library";
PATHS.folderPaths = {
    list: "/notes/folders",
    create: "/notes/folders/create",
    update: "/notes/folders/__id__/update",
    move: "/notes/folders/__id__/move",
    delete: "/notes/folders/__id__/delete",
    reorder: "/notes/folders/reorder",
    show: "/notes/folders/__id__",
};
PATHS.spacePaths = {
    list: "/notes/spaces",
    create: "/notes/spaces/create",
    show: "/notes/spaces/__id__",
    update: "/notes/spaces/__id__/update",
    delete: "/notes/spaces/__id__/delete",
    membersSet: "/notes/spaces/__id__/members",
    membersRemove: "/notes/spaces/__id__/members/__user__/remove",
    people: "/notes/spaces/people",
};

const NOTES = [
    { id: 1, title: "Journal", folderId: null, tags: [] },
    { id: 2, title: "Devis Lumen", folderId: 7, tags: [] },
];
const FOLDERS = [
    { id: 7, name: "Clients", parentId: null, noteCount: 1, folderCount: 0 },
];

/**
 * The note's menu: what is done once per note lives there, rather than
 * taking up the title line.
 */
async function openNoteMenu(wrapper) {
    const trigger = wrapper
        .findAll("button")
        // The bar's "Actions" button (AppPageActions since 02/10/2026).
        .find(
            (button) =>
                "shared.actions.plain_title" === button.attributes("title"),
        );

    await trigger.trigger("click");
    await flushPromises();

    return [...document.body.querySelectorAll("button, a")];
}

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
    installMatchMedia();
    global.fetch = vi.fn().mockResolvedValue({
        ok: true,
        status: 200,
        json: async () => ({
            success: true,
            notes: NOTES,
            folders: FOLDERS,
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

/**
 * A note can carry its own background: so the way back must not live on it.
 * On white paper, its hover took the back-office ink colour - almost white
 * in the dark theme - and the link disappeared.
 */
describe("le chemin de retour", () => {
    it("lives outside the note's own card", async () => {
        const wrapper = render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();

        // A real link now, to the library's address (07/10/2026).
        const back = wrapper.find("[data-back-link]");

        expect(back.exists(), "le lien de retour est là").toBe(true);
        expect(back.text()).toContain("notes.markdown.library.title");
        expect(
            back.element.closest("header"),
            "il n'est pas dans l'en-tête de la note",
        ).toBeNull();
    });
});

describe("les étiquettes de l'éditeur", () => {
    /**
     * Tags live in the note's menu since the title line was slimmed down:
     * twelve commands no longer left room for the title itself.
     */
    async function tagsEntry(wrapper) {
        const entrees = await openNoteMenu(wrapper);

        return entrees.find(
            (node) =>
                node.textContent.includes("tags.add_placeholder") ||
                node.textContent.includes("tags.summary"),
        );
    }

    /** The editor is only on screen once a note is open. */
    async function editing(tags = []) {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                success: true,
                notes: NOTES,
                folders: FOLDERS,
                note: { ...NOTES[0], tags },
                tags: [],
            }),
        });

        const wrapper = render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();

        return wrapper;
    }

    /**
     * They took up a whole line of the header all the time, for something
     * touched when the note is born and hardly ever after.
     */
    it("keeps its row out of the way until it is asked for", async () => {
        const wrapper = await editing();

        expect(wrapper.findComponent({ name: "AppTagsInput" }).exists()).toBe(
            false,
        );

        const entree = await tagsEntry(wrapper);

        expect(entree, "l'entrée des étiquettes est là").toBeTruthy();

        entree.click();
        await flushPromises();

        expect(wrapper.findComponent({ name: "AppTagsInput" }).exists()).toBe(
            true,
        );
    });

    /**
     * In a menu, the label names the tags - which says more than the number
     * the icon carried.
     */
    it("names them in the entry itself", async () => {
        const wrapper = await editing(["client", "photo"]);
        const entree = await tagsEntry(wrapper);

        expect(entree.textContent).toContain("client, photo");
    });
});

/**
 * Opening the share wiped the page.
 *
 * `api.preview` did not exist: the route was there, the path was passed to
 * the component, and the call started from a `watch` on the modal opening.
 * The exception became an unhandled rejection there - invisible - until the
 * page got a safety net, which made it spectacular.
 */
describe("le partage", () => {
    it("opens without taking the page down with it", async () => {
        const failures = [];
        const spy = vi
            .spyOn(console, "error")
            .mockImplementation((...consoleArguments) =>
                failures.push(consoleArguments.map(String).join(" ")),
            );

        const wrapper = render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();

        const entrees = await openNoteMenu(wrapper);
        const share = entrees.find((node) =>
            node.textContent.includes("share.button"),
        );

        expect(share, "l'entrée de partage est là").toBeTruthy();

        share.click();
        await flushPromises();

        spy.mockRestore();

        expect(failures.join("\n")).not.toContain("la page a échoué");
        expect(wrapper.text()).not.toContain("Aucune donnée à afficher");
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

        for (const [intent, intentArguments] of [
            ["select", [note.id]],
            ["create", [null]],
            ["delete", [note]],
            ["open-folder", [7]],
            ["create-folder", [null]],
            ["delete-folder", [FOLDERS[0]]],
            ["drop", [FOLDERS[0], event]],
        ]) {
            expect(askPage(`notes:${intent}`, { args: intentArguments })).toBe(
                true,
            );
        }
    });
});

/**
 * What the panel asks for must change the screen, not just find someone at
 * the other end of the line.
 *
 * The previous test checked that the intent was *answered* - `askPage`
 * returns true as soon as a listener exists - which let through a library
 * that had never received the order. Axel clicked a folder and stayed on
 * "Tous les documents".
 */
describe("ouvrir un dossier depuis le panneau", () => {
    it("shows what the folder holds, not the root", async () => {
        const wrapper = render();
        await flushPromises();

        expect(wrapper.text()).toContain("Journal");

        askPage("notes:open-folder", { args: [7] });
        await flushPromises();

        // The folder holds "Devis Lumen" and nothing else; "Journal" is at
        // the root, so it disappears from the grid.
        const cards = wrapper.findAll("article").map((one) => one.text());
        expect(cards.some((text) => text.includes("Devis Lumen"))).toBe(true);
        expect(cards.some((text) => text.includes("Journal"))).toBe(false);
    });

    it("comes back to the root when the panel asks for it", async () => {
        const wrapper = render();
        await flushPromises();

        askPage("notes:open-folder", { args: [7] });
        await flushPromises();

        askPage("notes:open-folder", { args: [null] });
        await flushPromises();

        const cards = wrapper.findAll("article").map((one) => one.text());
        expect(cards.some((text) => text.includes("Journal"))).toBe(true);
    });
});

/**
 * From the editor, the library is not mounted. Yet it lives in the same
 * application: leaving one for the other is a change of display, not a
 * reload - the reload threw away the scroll, the selection and the expanded
 * tree only to come back to the same place.
 */
describe("ouvrir un dossier avec une note ouverte", () => {
    it("swaps the editor for the folder, without reloading", async () => {
        const assign = vi.fn();
        const original = window.location;
        Object.defineProperty(window, "location", {
            configurable: true,
            value: {
                ...original,
                assign,
                pathname: "/suite/notes/markdown/1",
            },
        });

        const wrapper = render({ activeId: 1 });
        await flushPromises();

        // A note is open: the editor takes the space, not the grid.
        expect(wrapper.findAll("article")).toHaveLength(0);

        askPage("notes:open-folder", { args: [7] });
        await flushPromises();

        const cards = wrapper.findAll("article").map((one) => one.text());
        expect(cards.some((text) => text.includes("Devis Lumen"))).toBe(true);
        expect(assign, "aucun rechargement").not.toHaveBeenCalled();

        Object.defineProperty(window, "location", {
            configurable: true,
            value: original,
        });
    });

    it("opens the folder dialog after coming back from a note", async () => {
        const wrapper = render({ activeId: 1 });
        await flushPromises();

        askPage("notes:create-folder", { args: [null] });
        await flushPromises();

        expect(wrapper.findAll("article").length).toBeGreaterThan(0);
        expect(document.body.textContent).toContain("folders.create");

        document.body.innerHTML = "";
    });
});

/**
 * Deleting a folder from the menu takes its notes with it, possibly
 * including the one being written: leaving it open would make the autosave
 * write into a note in the trash.
 */
describe("la note ouverte disparaît sous nos pieds", () => {
    it("closes the editor when the note is no longer in the list", async () => {
        const wrapper = render({ activeId: 1 });
        await flushPromises();

        // The editor holds note 1.
        expect(wrapper.findAll("article")).toHaveLength(0);

        // The server no longer returns it: it left with its folder.
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ success: true, notes: [], folders: FOLDERS }),
        });

        askPage("notes:delete-folder", { args: [{ id: 7 }] });
        await flushPromises();

        // Back to the library rather than an editor on nothing.
        expect(wrapper.text()).toContain("library.title");
        document.body.innerHTML = "";
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
            (button) => /delete|confirm/.test(button.textContent),
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

/**
 * The graph had every part but the way in: the component was mounted, wired
 * to its endpoint and translated, and `graphOpen` was never set to true. The
 * page of documentation about it published a black rectangle, because there
 * was nothing to photograph.
 */
describe("the way into the graph", () => {
    // The graph is an editor command: with no note open, the page shows
    // the library, which has no button for it.
    it("opens the graph when its button is pressed", async () => {
        const wrapper = render({ activeId: 1 });
        await flushPromises();

        // The note opens through the bridge, as the panel would do it: it is
        // the same path as a reader's, and it does not depend on the order in
        // which the cases of this file run.
        askPage("notes:select", { args: [1] });
        await flushPromises();

        const graph = wrapper.findComponent({ name: "NoteGraph" });
        expect(graph.props("show")).toBe(false);

        const entrees = await openNoteMenu(wrapper);
        const button = entrees.find((node) =>
            node.textContent.includes("notes.markdown.graph.open"),
        );

        expect(button, "aucune entrée pour ouvrir le graphe").toBeDefined();

        button.click();
        await flushPromises();

        expect(wrapper.findComponent({ name: "NoteGraph" }).props("show")).toBe(
            true,
        );
    });
});

describe("ce que le panneau demande depuis l'éditeur", () => {
    /** The calls made, address and body, in order. */
    const calls = () =>
        global.fetch.mock.calls.map(([url, init]) => ({
            url: String(url),
            body: init?.body ? JSON.parse(init.body) : null,
        }));

    /**
     * Axel's bug: a note dragged onto a folder while a note is open did not
     * move. The page now writes the drop itself, without going through the
     * library.
     */
    it("files a dropped note even while a note is open", async () => {
        render();
        await flushPromises();
        askPage("notes:select", { args: [1] });
        await flushPromises();
        global.fetch.mockClear();

        expect(
            askPage("notes:move", {
                args: [
                    {
                        kind: "note",
                        id: 1,
                        folderId: 7,
                        fromFolderId: null,
                        order: [
                            { kind: "note", id: 2 },
                            { kind: "note", id: 1 },
                        ],
                    },
                ],
            }),
        ).toBe(true);
        await flushPromises();

        const made = calls();
        expect(made[0]).toEqual({
            url: "/notes/movePath",
            body: { folderId: 7, spaceId: null },
        });
        expect(made[1]).toEqual({
            url: "/notes/reorderPath",
            body: {
                entries: [
                    { id: 2, folderId: 7, position: 0 },
                    { id: 1, folderId: 7, position: 1 },
                ],
            },
        });
    });

    it("only reorders when the item stays in its folder", async () => {
        render();
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:move", {
            args: [
                {
                    kind: "folder",
                    id: 7,
                    folderId: null,
                    fromFolderId: null,
                    order: [{ kind: "folder", id: 7 }],
                },
            ],
        });
        await flushPromises();

        const made = calls();
        expect(made.some((one) => one.url.includes("/move"))).toBe(false);
        expect(made[0]).toEqual({
            url: "/notes/folders/reorder",
            body: { entries: [{ id: 7, parentId: null, position: 0 }] },
        });
    });

    /**
     * Folders and notes share one order: each goes by the route of its kind,
     * with its rank in the mixed list.
     */
    it("sends a mixed order through both reorder routes", async () => {
        render();
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:move", {
            args: [
                {
                    kind: "note",
                    id: 2,
                    folderId: 7,
                    fromFolderId: 7,
                    order: [
                        { kind: "note", id: 2 },
                        { kind: "folder", id: 9 },
                        { kind: "note", id: 3 },
                    ],
                },
            ],
        });
        await flushPromises();

        const made = calls();
        expect(made).toContainEqual({
            url: "/notes/folders/reorder",
            body: { entries: [{ id: 9, parentId: 7, position: 1 }] },
        });
        expect(made).toContainEqual({
            url: "/notes/reorderPath",
            body: {
                entries: [
                    { id: 2, folderId: 7, position: 0 },
                    { id: 3, folderId: 7, position: 2 },
                ],
            },
        });
    });

    /** A folder's plus opens a modal that creates a note or a folder. */
    it("creates a folder where the plus was pressed", async () => {
        render();
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:add", { args: [7] });
        await flushPromises();

        document.body.querySelector('[data-add-kind="folder"]').click();
        await flushPromises();

        const input = document.body.querySelector(
            "[data-add-name] input, input[data-add-name]",
        );
        input.value = "Devis 2026";
        input.dispatchEvent(new Event("input"));
        await flushPromises();

        document.body.querySelector("[data-add-submit]").click();
        await flushPromises();

        expect(calls()[0]).toEqual({
            url: "/notes/folders/create",
            body: {
                name: "Devis 2026",
                parentId: 7,
                color: null,
                spaceId: null,
            },
        });
    });

    /** Whoever is allowed to creates a space from the same modal. */
    it("creates a space from the add modal", async () => {
        render({ canCreateSpace: true });
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:add", { args: [null] });
        await flushPromises();

        document.body.querySelector('[data-add-kind="space"]').click();
        await flushPromises();

        const input = document.body.querySelector(
            "[data-add-name] input, input[data-add-name]",
        );
        input.value = "Documentation";
        input.dispatchEvent(new Event("input"));
        await flushPromises();

        document.body.querySelector("[data-add-submit]").click();
        await flushPromises();

        expect(calls()[0]).toEqual({
            url: "/notes/spaces/create",
            body: {
                name: "Documentation",
                color: null,
                access: "backoffice",
                defaultRole: "reader",
            },
        });
    });

    it("offers no space without the right to create one", async () => {
        render();
        await flushPromises();

        askPage("notes:add", { args: [null] });
        await flushPromises();

        expect(
            document.body.querySelector('[data-add-kind="space"]'),
        ).toBeNull();
    });

    /** A space header's plus files at the root of that space. */
    it("files a new note at the root of the space it was asked for", async () => {
        render({
            spaces: [
                { id: 1, personal: true, canWrite: true },
                { id: 5, name: "Équipe", personal: false, canWrite: true },
            ],
        });
        await flushPromises();
        global.fetch.mockClear();

        askPage("notes:add", { args: [{ folderId: null, spaceId: 5 }] });
        await flushPromises();

        expect(
            document.body.querySelector("[data-add-where]").textContent,
        ).toContain("Équipe");

        document.body.querySelector("[data-add-submit]").click();
        await flushPromises();

        expect(calls()[0]).toEqual({
            url: "/notes/createPath",
            body: { folderId: null, spaceId: 5, title: "", content: "" },
        });
    });

    it("writes where the open note lives, from the root", async () => {
        const wrapper = render({ folders: FOLDERS });
        await flushPromises();
        askPage("notes:select", { args: [2] });
        await flushPromises();

        const crumbs = wrapper
            .findAll("[data-note-crumb]")
            .map((one) => one.text());

        expect(crumbs).toEqual(["Clients"]);
    });
});

describe("emporter un espace seul", () => {
    /** A space header exports that space, and that space only. */
    it("exports the space the panel asks for", async () => {
        const assign = vi.fn();
        const original = window.location;
        Object.defineProperty(window, "location", {
            configurable: true,
            value: { ...original, assign },
        });

        render();
        await flushPromises();

        askPage("notes:export", { args: [5] });
        await flushPromises();
        askPage("notes:export", { args: [] });
        await flushPromises();

        expect(assign.mock.calls.map((call) => call[0])).toEqual([
            "/notes/exportPath?spaceId=5",
            "/notes/exportPath",
        ]);

        Object.defineProperty(window, "location", {
            configurable: true,
            value: original,
        });
    });
});
