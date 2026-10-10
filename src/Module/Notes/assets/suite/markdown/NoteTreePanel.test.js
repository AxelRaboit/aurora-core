import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";
import { onPanelRequest, tellPanels } from "@/shared/nav/modulePanelBridge.js";
import NoteTreeItem from "./components/NoteTreeItem.vue";

window.__isAdmin__ = true;

const NoteTreePanel = (await import("./NoteTreePanel.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    {
        id: 1,
        name: "Journal",
        parentId: null,
        noteCount: 1,
        folderCount: 1,
        favoritedAt: "2026-09-20T10:00:00+00:00",
    },
    { id: 2, name: "Lundi", parentId: 1, noteCount: 0, folderCount: 0 },
    { id: 3, name: "Recettes", parentId: null, noteCount: 2, folderCount: 0 },
];

const NOTES = [
    { id: 11, title: "Journal de bord", folderId: 1, tags: ["perso"] },
    { id: 12, title: "Tarte", folderId: 3, tags: ["cuisine"] },
];

/**
 * The panel asks for two lists, and a search when typing: so the answer
 * depends on the address, not on the call's rank.
 */
function answerWith({
    folders = FOLDERS,
    notes = NOTES,
    ids = [],
    ok = true,
    spaces = [],
    canCreate = false,
    craftEnabled = false,
} = {}) {
    global.fetch = vi.fn().mockImplementation(async (url) => {
        const path = String(url);
        const payload = path.includes("/folders")
            ? { success: true, folders }
            : path.includes("/search")
              ? { success: true, ids }
              : // The spaces: none by default, and the panel then keeps
                // its tree in one piece.
                path.includes("/notes/spaces")
                ? { success: true, spaces, canCreate, craftEnabled }
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

async function render(url = "/suite/notes/markdown", { expanded = [] } = {}) {
    window.history.replaceState({}, "", url);

    // The tree opens folded: a notebook of nine hundred notes expanded at
    // once is what the redesign removed. A case that looks at a branch
    // expands it, like the reader.
    window.localStorage.setItem(
        "aurora.notes.panel.expanded",
        JSON.stringify(expanded),
    );

    const wrapper = mount(NoteTreePanel, { global: { plugins: [i18n] } });
    mounted.push(wrapper);
    await flushPromises();

    return wrapper;
}

/**
 * The folder rows of the tree: neither "Tous les documents" at the top, nor
 * the favourites, which show the same addresses higher up.
 */
const folderLinks = (wrapper) =>
    wrapper
        .findAll("a")
        .filter(
            (link) =>
                link.attributes("href")?.includes("/folder/") &&
                undefined === link.attributes("data-favorite-row"),
        );

beforeEach(() => answerWith());

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    while (stops.length) stops.pop()();
    vi.restoreAllMocks();
});

describe("les espaces du panneau", () => {
    const SPACES = [
        {
            id: 1,
            name: null,
            personal: true,
            canWrite: true,
            canManage: true,
            position: 0,
        },
        {
            id: 7,
            name: "Équipe",
            personal: false,
            canWrite: false,
            canManage: false,
            position: 0,
        },
    ];
    const SPACED_FOLDERS = [
        { id: 1, name: "Journal", parentId: null, spaceId: 1 },
        { id: 9, name: "Procédures", parentId: null, spaceId: 7 },
    ];
    const SPACED_NOTES = [
        { id: 11, title: "Journal de bord", folderId: 1, spaceId: 1, tags: [] },
        { id: 91, title: "Compte rendu", folderId: 9, spaceId: 7, tags: [] },
    ];

    /**
     * One section per space, one's own first: what lives in a shared space
     * is filed under its name, and the header says it is only read.
     */
    it("groups the tree by space, one's own first", async () => {
        answerWith({
            spaces: SPACES,
            folders: SPACED_FOLDERS,
            notes: SPACED_NOTES,
        });

        const wrapper = await render("/suite/notes/markdown", {
            expanded: [9],
        });
        const headers = wrapper.findAll("[data-space-header]");

        expect(
            headers.map((one) => one.attributes("data-space-header")),
        ).toEqual(["1", "7"]);
        expect(headers[0].text()).toContain("notes.markdown.spaces.my_space");
        expect(headers[1].text()).toContain("Équipe");
        expect(headers[1].find("[data-space-readonly]").exists()).toBe(true);
        expect(wrapper.text()).toContain("Compte rendu");

        // A row of a space one only reads offers favourites only.
        const row = wrapper
            .findAllComponents(NoteTreeItem)
            .find((item) => "note:91" === item.props("node").key);
        expect(row.props("editable")).toBe(false);
        expect(row.vm.$.setupState.rowActions.map((one) => one.key)).toEqual([
            "favorite",
        ]);
    });

    /** Alone with its space, the panel stays the old one, without a header. */
    /**
     * Alone, one's space keeps its header: without it, nothing said where
     * the notes lived, and "Mon espace" could not be found.
     */
    it("names one's own space even when it is the only one", async () => {
        answerWith({ spaces: [SPACES[0]] });

        const wrapper = await render();
        const headers = wrapper.findAll("[data-space-header]");

        expect(headers).toHaveLength(1);
        expect(headers[0].text()).toContain("notes.markdown.spaces.my_space");
        expect(wrapper.text()).toContain("Journal");
    });

    /** As long as the spaces are not known, the tree shows without a header. */
    it("draws the tree without a header before the spaces arrive", async () => {
        const wrapper = await render();

        expect(wrapper.find("[data-space-header]").exists()).toBe(false);
        expect(wrapper.text()).toContain("Journal");
    });

    /** A header's plus adds at the root of that space. */
    it("asks the page to add at the root of a space", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:add", handler));
        answerWith({
            spaces: [SPACES[0], { ...SPACES[1], canWrite: true }],
            folders: SPACED_FOLDERS,
            notes: SPACED_NOTES,
        });

        const wrapper = await render();
        await wrapper.find('[data-space-add="7"]').trigger("click");

        expect(handler).toHaveBeenCalledWith({
            args: [{ folderId: null, spaceId: 7 }],
        });
    });

    /** Settings are only offered to whoever manages the space. */
    it("offers the settings to managers only", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:space-settings", handler));
        answerWith({
            spaces: [
                SPACES[0],
                { ...SPACES[1], canManage: true, canWrite: true },
                { id: 8, name: "Lu", canWrite: false, canManage: false },
            ],
            folders: SPACED_FOLDERS,
            notes: SPACED_NOTES,
        });

        const wrapper = await render();

        expect(wrapper.find('[data-space-settings="8"]').exists()).toBe(false);
        await wrapper.find('[data-space-settings="7"]').trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [7] });
    });

    /**
     * The Craft import is a gesture of the menu of a space one writes in,
     * and only when the connection is open.
     */
    it("offers the Craft import where one writes, once the connection is open", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:craft-import", handler));
        const spaces = [
            SPACES[0],
            { ...SPACES[1], canWrite: true },
            { id: 8, name: "Lu", canWrite: false, canManage: false },
        ];

        const menuKeys = (wrapper, id) =>
            wrapper
                .findAllComponents({ name: "AppRowActions" })
                .find(
                    (menu) =>
                        String(menu.attributes("data-space-menu")) ===
                        String(id),
                )
                .props("actions");

        answerWith({ spaces, folders: SPACED_FOLDERS, notes: SPACED_NOTES });
        const closed = await render();
        expect(menuKeys(closed, 7).map((action) => action.key)).not.toContain(
            "craft-import",
        );

        answerWith({
            spaces,
            folders: SPACED_FOLDERS,
            notes: SPACED_NOTES,
            craftEnabled: true,
        });
        const open = await render();
        expect(menuKeys(open, 8).map((action) => action.key)).not.toContain(
            "craft-import",
        );
        menuKeys(open, 7)
            .find((action) => "craft-import" === action.key)
            .onSelect();

        expect(handler).toHaveBeenCalledWith({ args: [7] });
    });

    /**
     * A space another module hosts is set there: its settings do not open
     * from here, even for whoever manages it.
     */
    it("keeps the settings of a managed space closed", async () => {
        answerWith({
            spaces: [
                SPACES[0],
                {
                    ...SPACES[1],
                    canManage: true,
                    canWrite: true,
                    managed: true,
                },
            ],
            folders: SPACED_FOLDERS,
            notes: SPACED_NOTES,
        });

        const wrapper = await render();

        expect(wrapper.find('[data-space-settings="7"]').exists()).toBe(false);
    });
});

describe("les étiquettes du panneau", () => {
    /** A tag's row, targeted by its label. */
    function tagRow(wrapper, name) {
        return wrapper
            .findAll("button")
            .find(
                (button) =>
                    button
                        .attributes("title")
                        ?.includes(`library.tag.filter`) &&
                    button.text().includes(name),
            );
    }

    beforeEach(() =>
        window.localStorage.removeItem("aurora.notes.panel.pinnedTags"),
    );

    it("lists what the notes actually carry, and how many carry it", async () => {
        const wrapper = await render();

        expect(tagRow(wrapper, "perso")).toBeTruthy();
        expect(tagRow(wrapper, "cuisine")).toBeTruthy();
        expect(tagRow(wrapper, "perso").text()).toContain("1");
    });

    it("asks the page to show a tag rather than navigating", async () => {
        const asked = [];
        stops.push(
            onPanelRequest("notes:filter-tag", ({ args: tagArguments }) =>
                asked.push(tagArguments),
            ),
        );

        const wrapper = await render();
        await tagRow(wrapper, "cuisine").trigger("click");

        expect(asked).toEqual([["cuisine"]]);
    });

    /**
     * Pinning lives in the browser: a tag is not a row in Aurora, it is a
     * string in a note's array.
     */
    it("remembers a pinned tag for the next visit", async () => {
        const wrapper = await render();

        const pin = wrapper
            .findAll("button")
            .find((button) =>
                button.attributes("title")?.includes("library.tag.pin"),
            );

        await pin.trigger("click");

        expect(
            JSON.parse(
                window.localStorage.getItem("aurora.notes.panel.pinnedTags"),
            ),
        ).toHaveLength(1);
    });

    /**
     * A tag renamed or deleted from the tags screen has no row to clean up:
     * the list starts from what the notes carry.
     */
    it("drops a pinned tag that no note carries any more", async () => {
        window.localStorage.setItem(
            "aurora.notes.panel.pinnedTags",
            JSON.stringify(["disparue"]),
        );

        const wrapper = await render();

        expect(tagRow(wrapper, "disparue")).toBeFalsy();
        expect(tagRow(wrapper, "perso")).toBeTruthy();
    });

    it("says nothing while a search is running", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("journal");
        await flushPromises();

        expect(tagRow(wrapper, "perso")).toBeFalsy();
    });
});

describe("the folders panel", () => {
    it("fetches its own lists, because the menu hands it no props", async () => {
        await render();

        expect(global.fetch).toHaveBeenCalledWith(
            "/suite/notes/markdown/folders",
            expect.anything(),
        );
        expect(global.fetch).toHaveBeenCalledWith(
            "/suite/notes/markdown/list",
            expect.anything(),
        );
    });

    /**
     * A folder is a page now, so a row is a real address: it can be sent to
     * somebody and opened in a new tab.
     */
    it("points every row at the folder's own address", async () => {
        const hrefs = folderLinks(
            await render("/suite/notes/markdown", { expanded: [1] }),
        ).map((link) => link.attributes("href"));

        expect(hrefs).toEqual([
            "/suite/notes/markdown/folder/1",
            "/suite/notes/markdown/folder/2",
            "/suite/notes/markdown/folder/3",
        ]);
    });

    /**
     * What expanding is for: seeing what a folder holds without leaving the
     * menu. Folded, the row only says how many.
     */
    it("shows the notes of a folder once it is unfolded", async () => {
        const wrapper = await render();

        expect(wrapper.text()).not.toContain("Journal de bord");

        const chevron = wrapper
            .find("[data-folder-row='1']")
            .findAll("button")
            .at(0);
        await chevron.trigger("click");

        expect(wrapper.text()).toContain("Journal de bord");
    });

    it("remembers what was unfolded, because the panel is remounted on every page", async () => {
        const first = await render();

        await first
            .find("[data-folder-row='1']")
            .findAll("button")
            .at(0)
            .trigger("click");

        const second = mount(NoteTreePanel, { global: { plugins: [i18n] } });
        mounted.push(second);
        await flushPromises();

        expect(second.text()).toContain("Journal de bord");
    });

    it("nests a folder under its parent", async () => {
        // The indent is on the row, not on the link inside it: the row is the
        // drop target and the draggable handle, the link is only the name.
        const indents = (
            await render("/suite/notes/markdown", { expanded: [1] })
        )
            .findAll("[data-folder-row]")
            .map((row) => row.attributes("style") ?? "");

        expect(indents[0]).not.toEqual(indents[1]);
        expect(indents[0]).toEqual(indents[2]);
    });

    /** The library is mounted, so it takes the click and swaps in place. */
    it("lets the page open the folder instead of navigating", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:open-folder", handler));

        await folderLinks(
            await render("/suite/notes/markdown", { expanded: [1] }),
        )[2].trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [3] });
    });

    /**
     * A single plus, which asks what. There used to be two side by side, a
     * note and a folder, told apart only by the shape of the icon.
     */
    it("asks the page to add something at the root", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:add", handler));

        const wrapper = await render();
        const plus = wrapper
            .findAll("button")
            .find(
                (button) =>
                    button.attributes("title") === "notes.markdown.add.title",
            );
        await plus.trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [null] });
    });

    /**
     * Axel's case: he could not find where to add a favourite. The row
     * offers it, and the page does it so that everything follows.
     */
    it("asks the page to toggle a favourite from a row", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:favorite", handler));

        const wrapper = await render("/suite/notes/markdown", {
            expanded: [1],
        });
        const row = wrapper
            .findAllComponents(NoteTreeItem)
            .find((item) => "note:11" === item.props("node").key);
        const action = row.vm.$.setupState.rowActions.find(
            (one) => "favorite" === one.key,
        );

        expect(action.title).toBe("notes.markdown.library.pin");

        action.onSelect();
        await flushPromises();

        expect(handler).toHaveBeenCalledWith({
            args: [{ kind: "note", id: 11 }],
        });
    });

    /** Axel's case: a folder's plus could only create a note. */
    it("asks the page to add inside the folder whose plus was pressed", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:add", handler));

        const wrapper = await render();
        const plus = wrapper
            .find('[data-folder-row="3"]')
            .findAll("button")
            .find(
                (button) =>
                    button.attributes("title") ===
                    "notes.markdown.create_in_folder",
            );
        await plus.trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [3] });
    });

    it("filters the tree on what the reader typed", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("recett");
        await flushPromises();

        expect(folderLinks(wrapper).map((link) => link.text())).toEqual([
            "Recettes",
        ]);
    });

    /**
     * The one search that reaches the whole notebook.
     *
     * The library filters the folder it shows, which is what an explorer
     * does; finding a note whose folder you have forgotten is this field's
     * job, and the matched notes are listed flat beneath the folders.
     */
    /**
     * A search opens the branches where it found something: leaving the
     * result folded means showing nothing.
     */
    it("finds a note across the notebook and opens its folder", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("tarte");
        await flushPromises();

        expect(wrapper.text()).toContain("Tarte");
        // Its folder stays shown, otherwise the found note would have no
        // branch left to hang from.
        expect(wrapper.text()).toContain("Recettes");
        expect(wrapper.text()).not.toContain("Journal de bord");
    });

    it("disappears rather than complaining when the fetch fails", async () => {
        answerWith({ ok: false });

        const wrapper = await render();

        expect(wrapper.text()).toBe("");
    });
});

describe("la racine", () => {
    /**
     * `Number(null)` is zero, and zero is not a folder: the panel asked for
     * folder 0, the library showed it empty and the address returned a 404 -
     * which sends a logged-out visitor to the login page. Axel saw all three
     * symptoms at once.
     */
    it("asks for the root, not for folder zero", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:open-folder", handler));

        const wrapper = await render();
        await wrapper.find("[data-root-row]").trigger("click");

        expect(handler).toHaveBeenCalledWith({ args: [null] });
    });
});

describe("les favoris", () => {
    /**
     * Craft opens its menu on them, and it is the only place from which a
     * note is reached in one click without knowing where it is filed.
     */
    it("lists what is pinned, above the tree", async () => {
        const wrapper = await render();

        expect(wrapper.text()).toContain("library.favorites");

        expect(wrapper.findAll("[data-favorite-row]")).toHaveLength(1);
        expect(wrapper.find("[data-favorite-row]").text()).toBe("Journal");
    });

    it("hides them while a search is running", async () => {
        const wrapper = await render();

        await wrapper.find("input").setValue("recett");
        await flushPromises();

        expect(wrapper.text()).not.toContain("library.favorites");
    });
});

describe("what the panel kept from the aside", () => {
    /**
     * The plus stays outside, the rest goes into the sheet.
     *
     * Before, a folder row carried two buttons - create and delete - and a
     * note row carried none: no rename, no delete, although everything
     * existed behind. The house rule says that beyond two gestures we stack,
     * and a tree row is too narrow to line up three: deleting and renaming
     * are therefore in the sheet, and a folder's plus - the only one that is
     * repeated - stays at hand.
     */
    it("garde le plus dehors et passe le reste dans la feuille", async () => {
        const titles = (await render())
            .findAll("button")
            .map((button) => button.attributes("title"))
            .filter(Boolean);

        expect(titles.some((t) => t.includes("create_in_folder"))).toBe(true);
        expect(titles.some((t) => t.includes("shared.actions.open"))).toBe(
            true,
        );
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
    /**
     * Axel's case: creating a note in a folded folder left it invisible. Yet
     * the panel's comment promised that "son dossier s'ouvre", and nothing
     * checked it.
     */
    it("ouvre le dossier d'une note quand la page l'annonce", async () => {
        const wrapper = await render("/suite/notes/markdown", {
            expanded: [],
        });

        expect(wrapper.text()).not.toContain("Journal de bord");

        tellPanels("notes:changed", {
            notes: NOTES,
            folders: FOLDERS,
            noteId: 11,
        });
        await flushPromises();

        expect(wrapper.text()).toContain("Journal de bord");
    });

    /** A deeply filed note is only visible if the whole chain opens. */
    it("remonte toute la chaîne des dossiers, pas seulement le dernier", async () => {
        const wrapper = await render("/suite/notes/markdown", {
            expanded: [],
        });

        const profond = [
            ...NOTES,
            { id: 13, title: "Sous-note", folderId: 2, tags: [] },
        ];

        tellPanels("notes:changed", {
            notes: profond,
            folders: FOLDERS,
            noteId: 13,
        });
        await flushPromises();

        // "Lundi" is in "Journal": both must have opened.
        expect(wrapper.text()).toContain("Lundi");
        expect(wrapper.text()).toContain("Sous-note");
    });

    it("takes the page's word for the list when it changes", async () => {
        const wrapper = await render("/suite/notes/markdown", {
            expanded: [1],
        });
        expect(folderLinks(wrapper)).toHaveLength(3);

        tellPanels("notes:changed", {
            folders: [...FOLDERS, { id: 4, name: "Neuf", parentId: null }],
        });
        await flushPromises();

        expect(folderLinks(wrapper).map((link) => link.text())).toContain(
            "Neuf",
        );
    });
});

/**
 * A simulated drag: what the browser gives, the readable types on hover and
 * the content on drop only.
 */
function transferFor(kind, id) {
    const data = {};
    const transfer = {
        setData: (type, value) => {
            data[type] = value;
        },
        getData: (type) => data[type] ?? "",
        get types() {
            return Object.keys(data);
        },
        effectAllowed: "",
        dropEffect: "",
    };

    transfer.setData("application/x-aurora-note-item", `${kind}:${id}`);
    transfer.setData(`application/x-aurora-note-kind-${kind}`, "");
    transfer.setData(`application/x-aurora-note-id-${id}`, "");

    return transfer;
}

/** The middle of a row: "inside" for a folder. */
function middleOf(row) {
    row.element.getBoundingClientRect = () => ({
        top: 0,
        height: 40,
        left: 0,
        width: 200,
        bottom: 40,
        right: 200,
    });

    return { clientY: 20 };
}

describe("le glisser-déposer du panneau", () => {
    /**
     * Axel's bug: dragging a note onto a folder from the editor did nothing,
     * because the panel handed the drop to the library, which is absent when
     * a note is open. The panel now computes the filing and asks the page
     * for it as data.
     */
    it("asks the page to file a note into the folder it was dropped on", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:move", handler));

        const wrapper = await render();
        const row = wrapper.find('[data-folder-row="1"]');
        const dataTransfer = transferFor("note", 12);

        await row.trigger("dragover", { dataTransfer, ...middleOf(row) });
        expect(row.attributes("data-drop-zone")).toBe("inside");

        await row.trigger("drop", { dataTransfer, ...middleOf(row) });

        expect(handler).toHaveBeenCalledWith({
            args: [
                {
                    kind: "note",
                    id: 12,
                    folderId: 1,
                    fromFolderId: 3,
                    spaceId: null,
                    fromSpaceId: null,
                    // The folder's folders and notes, mixed in their order.
                    order: [
                        { kind: "folder", id: 2 },
                        { kind: "note", id: 11 },
                        { kind: "note", id: 12 },
                    ],
                },
            ],
        });
    });

    it("lights nothing and files nothing for a folder dropped into its own child", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:move", handler));

        const wrapper = await render("/suite/notes/markdown", {
            expanded: [1],
        });
        const row = wrapper.find('[data-folder-row="2"]');
        const dataTransfer = transferFor("folder", 1);

        await row.trigger("dragover", { dataTransfer, ...middleOf(row) });
        expect(row.attributes("data-drop-zone")).toBeUndefined();

        await row.trigger("drop", { dataTransfer, ...middleOf(row) });
        expect(handler).not.toHaveBeenCalled();
    });

    it("brings a folder back to the root when dropped on the root row", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:move", handler));

        const wrapper = await render();
        const root = wrapper.find("[data-root-row]");

        await root.trigger("drop", { dataTransfer: transferFor("folder", 2) });

        expect(handler).toHaveBeenCalledWith({
            args: [
                expect.objectContaining({
                    kind: "folder",
                    id: 2,
                    folderId: null,
                    fromFolderId: 1,
                }),
            ],
        });
    });

    it("ignores what is not one of ours, like a file from the desktop", async () => {
        const handler = vi.fn();
        stops.push(onPanelRequest("notes:move", handler));

        const wrapper = await render();
        const row = wrapper.find('[data-folder-row="3"]');
        const dataTransfer = {
            types: ["Files"],
            getData: () => "",
            effectAllowed: "",
            dropEffect: "",
        };

        await row.trigger("drop", { dataTransfer, ...middleOf(row) });

        expect(handler).not.toHaveBeenCalled();
    });
});

describe("le confort de l'arbre", () => {
    it("unfolds a folder when it is opened", async () => {
        const wrapper = await render();

        await folderLinks(wrapper)
            .find((link) => link.text().includes("Journal"))
            .trigger("click");

        expect(wrapper.text()).toContain("Lundi");
    });

    it("folds everything back in one gesture", async () => {
        const wrapper = await render("/suite/notes/markdown", {
            expanded: [1, 3],
        });
        expect(wrapper.text()).toContain("Tarte");

        await wrapper
            .findAll("button")
            .find(
                (button) =>
                    button.attributes("title") ===
                    "notes.markdown.collapse_all",
            )
            .trigger("click");

        expect(wrapper.text()).not.toContain("Tarte");
    });

    /** Up and down move from one row to the next, right expands. */
    it("walks the tree with the arrow keys", async () => {
        const wrapper = await render();
        const rows = wrapper.findAll("[data-tree-row]");

        rows[0].element.focus = vi.fn();
        rows[1].element.focus = vi.fn();

        await rows[0].trigger("keydown", { key: "ArrowDown" });
        expect(rows[1].element.focus).toHaveBeenCalled();

        await rows[0].trigger("keydown", { key: "ArrowRight" });
        expect(wrapper.text()).toContain("Lundi");
    });
});

describe("passer en lecture", () => {
    it("opens the reader with Alt+R from any notes screen", async () => {
        const assign = vi.fn();
        vi.spyOn(window, "location", "get").mockReturnValue({
            ...window.location,
            assign,
        });

        await render();
        window.dispatchEvent(
            new KeyboardEvent("keydown", {
                altKey: true,
                code: "KeyR",
                key: "®",
            }),
        );

        expect(assign).toHaveBeenCalledWith("/suite/notes/markdown/11/read");
    });

    /**
     * One had to open a note then look for "Lire" in its three dots. The
     * panel now carries the button, always there.
     */
    it("opens the reader on the first note when none is open", async () => {
        const assign = vi.fn();
        vi.spyOn(window, "location", "get").mockReturnValue({
            ...window.location,
            assign,
        });

        const wrapper = await render();
        await wrapper.find("[data-read-mode-toggle]").trigger("click");

        expect(assign).toHaveBeenCalledWith("/suite/notes/markdown/11/read");
    });

    it("opens the reader on the note the page says is open", async () => {
        const assign = vi.fn();
        vi.spyOn(window, "location", "get").mockReturnValue({
            ...window.location,
            assign,
        });

        const wrapper = await render();
        tellPanels("notes:changed", {
            notes: NOTES,
            folders: FOLDERS,
            noteId: 12,
        });
        await flushPromises();
        await wrapper.find("[data-read-mode-toggle]").trigger("click");

        expect(assign).toHaveBeenCalledWith("/suite/notes/markdown/12/read");
    });
});
