import { describe, it, expect, vi, beforeEach, afterEach } from "vitest";
import { mount, flushPromises } from "@vue/test-utils";
import { createTestI18n } from "@/tests/helpers/createTestI18n.js";

window.__isAdmin__ = true;

const NoteLibrary = (await import("./NoteLibrary.vue")).default;

const i18n = createTestI18n();

const FOLDERS = [
    {
        id: 1,
        parentId: null,
        name: "Clients",
        color: "#22c55e",
        position: 0,
        noteCount: 2,
        folderCount: 1,
        updatedAt: "2026-09-01T10:00:00+00:00",
        createdAt: "2026-01-01T10:00:00+00:00",
    },
    {
        id: 2,
        parentId: 1,
        name: "Studio Lumen",
        position: 0,
        noteCount: 0,
        folderCount: 0,
        updatedAt: "2026-09-02T10:00:00+00:00",
        createdAt: "2026-02-01T10:00:00+00:00",
    },
];

const NOTES = [
    {
        id: 11,
        folderId: null,
        title: "À la racine",
        excerpt:
            "## Repérage\n\n- lumière de fin de journée\n- une heure de battement",
        tags: ["essai"],
        position: 0,
        updatedAt: "2026-09-20T10:00:00+00:00",
        createdAt: "2026-04-01T10:00:00+00:00",
    },
    {
        id: 12,
        folderId: 1,
        title: "Devis",
        tags: [],
        position: 0,
        updatedAt: "2026-09-21T10:00:00+00:00",
        createdAt: "2026-05-01T10:00:00+00:00",
    },
];

function apis() {
    return {
        foldersApi: {
            list: vi.fn(),
            favorite: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            create: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            rename: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn(),
            urlFor: (id) => `/suite/notes/markdown/folder/${id}`,
        },
        notesApi: {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            favorite: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        },
    };
}

/**
 * A card's menu, targeted by its label and not by its position.
 *
 * It was "the card's last button" for a while, and the clickable tags added
 * below it brought down three cases at once.
 */
function rowMenu(card) {
    return card
        .findAll("button")
        .find((b) => b.attributes("title")?.startsWith("shared.actions.open"));
}

const mounted = [];

function render(props = {}) {
    const wrapper = mount(NoteLibrary, {
        props: {
            folders: FOLDERS,
            notes: NOTES,
            ...apis(),
            initialFolderId: null,
            breadcrumb: [],
            rootUrl: "/suite/notes/markdown",
            noteUrlFor: (id) => `/suite/notes/markdown/${id}`,
            ...props,
        },
        global: { plugins: [i18n] },
    });
    mounted.push(wrapper);

    return wrapper;
}

beforeEach(() => {
    window.localStorage.clear();
    window.history.replaceState({}, "", "/suite/notes/markdown");
});

afterEach(() => {
    while (mounted.length) mounted.pop().unmount();
    // Action sheets teleport into the body: without this sweep, a case finds
    // the menu opened by the previous one.
    document.body.innerHTML = "";
    vi.restoreAllMocks();
});

describe("the library", () => {
    it("shows the root: its folders and the notes filed nowhere", () => {
        const cards = render()
            .findAll("article")
            .map((one) => one.text());

        expect(cards.some((text) => text.includes("Clients"))).toBe(true);
        expect(cards.some((text) => text.includes("À la racine"))).toBe(true);
        // What is filed in a folder is not at the root. The recent row, on
        // the other hand, cuts across the notebook: that is its job.
        expect(cards.some((text) => text.includes("Devis"))).toBe(false);
    });

    /**
     * "Where was I" is the question asked on arrival, and the recent row
     * answers it without making one search for which folder the note had
     * been filed in.
     */
    it("opens on the notes most recently changed, wherever they are filed", () => {
        const wrapper = render();

        const recent = wrapper.find("section");

        expect(recent.exists()).toBe(true);
        expect(recent.text()).toContain("Devis");
    });

    it("keeps the recent row out of a folder, where it would answer nothing", () => {
        expect(render({ initialFolderId: 1 }).find("section").exists()).toBe(
            false,
        );
    });

    it("shows what a folder holds when the address names one", () => {
        const text = render({ initialFolderId: 1 }).text();

        expect(text).toContain("Studio Lumen");
        expect(text).toContain("Devis");
        expect(text).not.toContain("À la racine");
    });

    /** Each card is an address: a middle click must behave. */
    it("points a note card at the note's own address", () => {
        const hrefs = render()
            .findAll("a")
            .map((a) => a.attributes("href"));

        expect(hrefs).toContain("/suite/notes/markdown/11");
    });

    it("opens a note through the page rather than navigating", async () => {
        const wrapper = render();

        await wrapper
            .findAll("a")
            .find((a) => a.attributes("href") === "/suite/notes/markdown/11")
            .trigger("click");

        expect(wrapper.emitted("open-note")?.[0]).toEqual([11]);
    });

    it("asks the page for a new note in the folder being looked at", async () => {
        const wrapper = render({ initialFolderId: 1 });

        // The button no longer carries its label, only its icon: its
        // tooltip names it.
        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") === "notes.markdown.library.new_note",
            )
            .trigger("click");

        expect(wrapper.emitted("create-note")?.[0]).toEqual([1]);
    });

    /**
     * The central gesture of the redesign: filing by dragging a card onto a
     * folder. What is moved travels in the event's clipboard, so the target
     * does not need to know what is coming.
     */
    it("files a note into the folder it is dropped on", async () => {
        const notesApi = {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi });

        const dataTransfer = {
            types: ["application/x-aurora-note-item"],
            getData: () => "note:11",
            setData: () => {},
            effectAllowed: "",
            dropEffect: "",
        };

        const card = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await card.trigger("drop", { dataTransfer });
        await flushPromises();

        expect(notesApi.move).toHaveBeenCalledWith(11, 1);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    it("refuses to drop a folder onto itself", async () => {
        const foldersApi = {
            ...apis().foldersApi,
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ foldersApi });

        const dataTransfer = {
            types: ["application/x-aurora-note-item"],
            getData: () => "folder:1",
            setData: () => {},
            effectAllowed: "",
            dropEffect: "",
        };

        const card = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await card.trigger("drop", { dataTransfer });
        await flushPromises();

        expect(foldersApi.move).not.toHaveBeenCalled();
    });

    /**
     * The excerpt only applies in the mosaic: the card view is dense by
     * choice, and the list shows columns.
     */
    it("shows the first lines in the mosaic, and only there", async () => {
        const wrapper = render();

        expect(wrapper.text()).toContain("lumière de fin de journée");

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.view.cards",
            )
            .trigger("click");

        expect(wrapper.text()).not.toContain("lumière de fin de journée");
    });

    /**
     * The manual order only makes sense if something can change it: a sort
     * criterion without a gesture to feed it is a menu that lies.
     *
     * The gesture is in the card's menu rather than on drag: dropping a card
     * onto another already means "file it inside", and telling the edge from
     * the middle of a card is easy to miss with a finger.
     */
    it("moves a note within its folder, in manual order", async () => {
        window.localStorage.setItem("aurora.notes.library.sort", "manual");

        const notesApi = {
            move: vi.fn(),
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };

        const wrapper = render({
            notesApi,
            folders: [],
            notes: [
                { ...NOTES[0], position: 0 },
                {
                    id: 14,
                    folderId: null,
                    title: "Seconde",
                    tags: [],
                    position: 1,
                    updatedAt: "2026-09-19T10:00:00+00:00",
                    createdAt: "2026-06-01T10:00:00+00:00",
                },
            ],
        });

        // Descending manual order by default: "Seconde", position 1, is at
        // the top, and "À la racine" is the one that can move up.
        const first = wrapper
            .findAll("article")
            .find((one) => one.text().includes("À la racine"));

        await rowMenu(first).trigger("click");
        await flushPromises();

        const up = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("sort.move_up"),
        );
        expect(up, "l'action monter est proposée").toBeTruthy();

        up.click();
        await flushPromises();

        // Moving up one step in descending order means taking the highest
        // position: the two notes swap their ranks.
        expect(notesApi.reorder).toHaveBeenCalledWith([
            { id: 14, folderId: null, position: 0 },
            { id: 11, folderId: null, position: 1 },
        ]);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    /**
     * Folders and notes share a single order, the one the tree shows mixed.
     * Moving a note one step must not overwrite the rank of the folder that
     * sits between them.
     */
    it("keeps a folder's rank when a note moves past its neighbour", async () => {
        window.localStorage.setItem("aurora.notes.library.sort", "manual");

        const notesApi = {
            ...apis().notesApi,
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const foldersApi = {
            ...apis().foldersApi,
            reorder: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };

        const wrapper = render({
            notesApi,
            foldersApi,
            folders: [
                {
                    id: 5,
                    parentId: null,
                    name: "Dossier",
                    position: 1,
                    color: null,
                },
            ],
            notes: [
                { ...NOTES[0], position: 0 },
                {
                    id: 14,
                    folderId: null,
                    title: "Seconde",
                    tags: [],
                    position: 2,
                    updatedAt: "2026-09-19T10:00:00+00:00",
                    createdAt: "2026-06-01T10:00:00+00:00",
                },
            ],
        });

        const first = wrapper
            .findAll("article")
            .find((one) => one.text().includes("À la racine"));

        await rowMenu(first).trigger("click");
        await flushPromises();

        [...document.body.querySelectorAll("button")]
            .find((b) => b.textContent.includes("sort.move_up"))
            .click();
        await flushPromises();

        // Before: note 11, folder 5, note 14. Note 11 moves after its
        // neighbour 14, and the folder keeps its place in front of them.
        expect(foldersApi.reorder).toHaveBeenCalledWith([
            { id: 5, parentId: null, position: 0 },
        ]);
        expect(notesApi.reorder).toHaveBeenCalledWith([
            { id: 14, folderId: null, position: 1 },
            { id: 11, folderId: null, position: 2 },
        ]);
    });

    it("keeps the order actions out of a sort that would undo them", async () => {
        const wrapper = render({ folders: [] });

        await rowMenu(wrapper.findAll("article")[0]).trigger("click");
        await flushPromises();

        expect(document.body.textContent).not.toContain("sort.move_up");
    });

    /**
     * Get rid of a note without having to open it: it could only be deleted
     * from the editor, so one had to read what one wanted to throw away.
     */
    it("deletes a note from the library, after asking", async () => {
        const notesApi = {
            move: vi.fn(),
            reorder: vi.fn(),
            remove: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi, folders: [] });

        await rowMenu(wrapper.findAll("article")[0]).trigger("click");
        await flushPromises();

        const remove = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("markdown.delete"),
        );
        expect(remove, "l'action supprimer est proposée").toBeTruthy();
        remove.click();
        await flushPromises();

        // The action sheet and the confirmation carry the same label; the one
        // that just appeared is the last in the document.
        const confirm = [...document.body.querySelectorAll("button")]
            .filter((b) => b.textContent.includes("markdown.delete"))
            .at(-1);
        expect(confirm, "la confirmation est à l'écran").toBeTruthy();
        confirm.click();
        await flushPromises();

        expect(notesApi.remove).toHaveBeenCalledWith(11);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    /**
     * Tidying a notebook is rarely moving one note: it is moving twelve. The
     * selection exists for those two gestures, and for no other - renaming
     * or exporting makes no sense in the plural.
     */
    it("moves everything that is selected, in one dialog", async () => {
        const notesApi = {
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
            reorder: vi.fn(),
            remove: vi.fn(),
        };
        const foldersApi = {
            ...apis().foldersApi,
            move: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi, foldersApi });
        await startSelecting(wrapper);

        // A note and a folder: the selection carries both kinds, and an id
        // alone would have mixed them up.
        const cards = wrapper.findAll("article");
        await cards[0].findAll("button")[0].trigger("click");
        await cards.at(-1).findAll("button")[0].trigger("click");

        expect(wrapper.text()).toContain("library.selected");

        await wrapper
            .findAll("button")
            .find((b) => b.text().includes("folders.move_to"))
            .trigger("click");
        await flushPromises();

        const confirm = [...document.body.querySelectorAll("button")]
            .filter((b) => b.textContent.includes("folders.move_to"))
            .at(-1);
        confirm.click();
        await flushPromises();

        expect(foldersApi.move).toHaveBeenCalledWith(1, null);
        expect(notesApi.move).toHaveBeenCalledWith(11, null);
        expect(wrapper.text()).not.toContain("library.selected");
    });

    it("forgets the selection when the folder changes", async () => {
        const wrapper = render();
        await startSelecting(wrapper);

        await wrapper
            .findAll("article")[0]
            .findAll("button")[0]
            .trigger("click");
        expect(wrapper.text()).toContain("library.selected");

        wrapper.vm.openFolder(1);
        await flushPromises();

        expect(wrapper.text()).not.toContain("library.selected");
    });

    /**
     * The keyboard, because an explorer without arrows forces one to aim.
     */
    describe("au clavier", () => {
        function press(key) {
            window.dispatchEvent(
                new KeyboardEvent("keydown", { key, bubbles: true }),
            );

            return flushPromises();
        }

        it("walks the cards and opens the one it stops on", async () => {
            const wrapper = render({ folders: [] });

            await press("ArrowDown");
            await press("Enter");

            expect(wrapper.emitted("open-note")?.[0]).toEqual([11]);
        });

        it("picks and unpicks with the space bar", async () => {
            const wrapper = render({ folders: [] });

            await press("ArrowDown");
            await press(" ");
            expect(wrapper.text()).toContain("library.selected");

            await press("Escape");
            expect(wrapper.text()).not.toContain("library.selected");
        });

        it("makes a note with n, and asks for a folder name with N", async () => {
            const wrapper = render();

            await press("n");
            expect(wrapper.emitted("create-note")?.[0]).toEqual([null]);

            await press("N");
            expect(document.body.textContent).toContain("folders.create");
        });

        /**
         * The trap of the one-letter shortcut: typing "nouvelle" in the
         * search would create a note for each "n".
         */
        it("keeps its hands off the keyboard while somebody types", async () => {
            const wrapper = render();

            await press("/");
            const input = wrapper.find("input");
            expect(input.exists(), "la barre oblique ouvre la recherche").toBe(
                true,
            );

            input.element.dispatchEvent(
                new KeyboardEvent("keydown", { key: "n", bubbles: true }),
            );
            await flushPromises();

            expect(wrapper.emitted("create-note")).toBeUndefined();
        });
    });

    it("renames a folder on a double click, as a file browser does", async () => {
        const wrapper = render();

        const folderCard = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await folderCard
            .findAll("button")
            .find((button) => button.text().includes("Clients"))
            .trigger("dblclick");

        expect(document.body.textContent).toContain("folders.rename");
    });

    it("pins a note to the side menu", async () => {
        const notesApi = {
            move: vi.fn(),
            reorder: vi.fn(),
            remove: vi.fn(),
            favorite: vi.fn().mockResolvedValue({ ok: true, payload: {} }),
        };
        const wrapper = render({ notesApi, folders: [] });

        await rowMenu(wrapper.findAll("article")[0]).trigger("click");
        await flushPromises();

        const pin = [...document.body.querySelectorAll("button")].find((b) =>
            b.textContent.includes("library.pin"),
        );
        expect(pin, "l'action épingler est proposée").toBeTruthy();
        pin.click();
        await flushPromises();

        expect(notesApi.favorite).toHaveBeenCalledWith(11);
        expect(wrapper.emitted("changed")).toBeTruthy();
    });

    /**
     * The defect that blanked the page: the server sent its dates as
     * objects, `Intl` threw, and the exception took the whole component
     * down. The server is fixed; this checks that the display holds even if
     * a date became unreadable again.
     */
    it("survives a date it cannot read", () => {
        const wrapper = render({
            folders: [],
            notes: [
                {
                    ...NOTES[0],
                    updatedAt: { date: "2026-09-23 04:59:53", timezone: "UTC" },
                    createdAt: null,
                },
            ],
        });

        expect(wrapper.findAll("article")).toHaveLength(1);
        expect(wrapper.text()).toContain("À la racine");
    });

    /**
     * A card is a wide target: making only its title clickable forces one to
     * aim at twenty pixels of text.
     */
    it("opens the note from anywhere on its card", async () => {
        const wrapper = render({ folders: [] });

        const card = wrapper
            .findAll("article")
            .find((one) => one.text().includes("À la racine"));

        // The excerpt, not the title: the click must count anyway.
        await card.find("p").trigger("click");

        expect(wrapper.emitted("open-note")?.[0]).toEqual([11]);
    });

    it("opens the folder from anywhere on its card", async () => {
        const wrapper = render();

        const card = wrapper
            .findAll("article")
            .find((one) => one.text().includes("Clients"));

        await card.find("p").trigger("click");

        expect(wrapper.text()).toContain("Devis");
    });

    /** The checkbox selects, it does not open. */
    it("does not open when the checkbox is pressed", async () => {
        const wrapper = render({ folders: [] });
        await startSelecting(wrapper);

        await wrapper
            .findAll("article")[0]
            .findAll("button")[0]
            .trigger("click");

        expect(wrapper.emitted("open-note")).toBeUndefined();
        expect(wrapper.text()).toContain("library.selected");
    });

    /**
     * As in the media library: the circles only show in selection mode, and
     * in that mode a click on the card ticks instead of opening.
     */
    it("shows no checkbox until selection mode is opened", async () => {
        const wrapper = render({ folders: [] });

        expect(
            wrapper
                .findAll("article")[0]
                .find("button[title$='library.select']")
                .exists(),
        ).toBe(false);

        await startSelecting(wrapper);
        await wrapper.findAll("article")[0].trigger("click");

        expect(wrapper.emitted("open-note")).toBeUndefined();
        expect(wrapper.text()).toContain("library.selected");
    });

    it("leaves selection mode with Escape, and forgets what was picked", async () => {
        const wrapper = render({ folders: [] });
        await startSelecting(wrapper);
        await wrapper.findAll("article")[0].trigger("click");

        window.dispatchEvent(
            new KeyboardEvent("keydown", { key: "Escape", bubbles: true }),
        );
        await flushPromises();

        expect(wrapper.text()).not.toContain("library.selected");
        await wrapper.findAll("article")[0].trigger("click");
        expect(wrapper.emitted("open-note")).toBeTruthy();
    });

    /**
     * An empty field taking a third of the bar costs that space to everything
     * else, and nobody searches all the time.
     */
    it("keeps the search folded until it is asked for", async () => {
        const wrapper = render();

        console.log(
            "INPUTS:",
            wrapper
                .findAll("input")
                .map(
                    (i) =>
                        i.attributes("type") +
                        "/" +
                        (i.attributes("placeholder") ?? ""),
                )
                .join(" | "),
        );
        expect(wrapper.find("input").exists()).toBe(false);

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.search_placeholder",
            )
            .trigger("click");

        expect(wrapper.find("input").exists()).toBe(true);
    });

    /** An active but invisible filter would make one wonder why the list is short. */
    it("stays open while something is typed in it", async () => {
        const wrapper = render();

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.search_placeholder",
            )
            .trigger("click");
        await wrapper.find("input").setValue("racine");
        await wrapper.find("input").trigger("keyup.esc");

        expect(wrapper.find("input").exists()).toBe(true);
    });

    /**
     * The sort folds too: it is changed in fits and starts, it does not have
     * to take up its width all the time. But it says what it is set to,
     * otherwise one would not know why the list is in this order.
     */
    it("folds the sort into an icon that names the current criterion", async () => {
        const wrapper = render();

        const trigger = wrapper
            .findAll("button")
            .find((b) =>
                b
                    .attributes("title")
                    ?.startsWith("notes.markdown.library.sort.label"),
            );

        expect(trigger, "l'icône du tri est là").toBeTruthy();
        expect(trigger.attributes("title")).toContain("sort.updated");

        await trigger.trigger("click");
        await flushPromises();

        expect(wrapper.findComponent({ name: "AppMultiselect" }).exists()).toBe(
            true,
        );
    });

    /**
     * And it folds when one leaves it, like the magnifier. The signal comes
     * from the selector itself: its panel is teleported into the `body`, so
     * a `focusout` set around it would fire on the click on an option,
     * before the choice arrives.
     */
    it("folds the sort back when the select closes", async () => {
        const wrapper = render();

        const trigger = () =>
            wrapper
                .findAll("button")
                .find((b) =>
                    b
                        .attributes("title")
                        ?.startsWith("notes.markdown.library.sort.label"),
                );

        await trigger().trigger("click");
        await flushPromises();

        expect(trigger()).toBeFalsy();

        wrapper.findComponent({ name: "AppMultiselect" }).vm.$emit("close");
        await flushPromises();

        expect(wrapper.findComponent({ name: "AppMultiselect" }).exists()).toBe(
            false,
        );
        expect(trigger(), "l'icône est revenue").toBeTruthy();
    });

    /**
     * The notebook has two readings: what the place contains, and everything
     * beneath it at once. The second is the one wanted when one no longer
     * knows which folder something was filed in.
     */
    describe("le tout-à-plat", () => {
        function scopeButton(wrapper) {
            return wrapper
                .findAll("button")
                .find((b) =>
                    b
                        .attributes("title")
                        ?.startsWith("notes.markdown.library.scope"),
                );
        }

        it("brings up what the folders hold, and drops the folder cards", async () => {
            const wrapper = render();

            await scopeButton(wrapper).trigger("click");

            const cards = wrapper.findAll("article").map((one) => one.text());

            // The two notes, the root's and the folder's, and nothing else:
            // a folder shown on top of the notes it holds would show the
            // same thing twice. "Clients" stays readable, but on the card of
            // the note it holds.
            expect(cards).toHaveLength(2);
            expect(cards.some((text) => text.includes("Devis"))).toBe(true);
            expect(cards.some((text) => text.includes("À la racine"))).toBe(
                true,
            );
            expect(
                cards.some((text) =>
                    text.includes("notes.markdown.folders.contents"),
                ),
            ).toBe(false);
        });

        it("says where a note lives, and takes the reader there", async () => {
            const wrapper = render();

            await scopeButton(wrapper).trigger("click");

            const chip = wrapper
                .findAll("button")
                .find((b) =>
                    b
                        .attributes("title")
                        ?.startsWith(
                            "notes.markdown.library.scope.open_folder",
                        ),
                );

            expect(chip, "la carte dit son dossier").toBeTruthy();

            await chip.trigger("click");

            expect(window.location.pathname).toBe(
                "/suite/notes/markdown/folder/1",
            );
        });

        it("keeps the folder name to itself when the list is filed", () => {
            const titles = render()
                .findAll("button")
                .map((b) => b.attributes("title") ?? "");

            expect(
                titles.some((title) => title.includes("scope.open_folder")),
            ).toBe(false);
        });
    });

    /**
     * A colour is set as a style and not as a class: it is chosen by the
     * reader, and Tailwind only writes the classes it sees in the source.
     */
    it("paints a folder's icon with the colour it carries", () => {
        const wrapper = render();

        const tinted = wrapper
            .findAll("svg")
            .filter((svg) =>
                svg.attributes("style")?.includes("rgb(34, 197, 94)"),
            );

        expect(tinted.length).toBeGreaterThan(0);
    });

    it("leaves a folder without a colour alone", () => {
        const wrapper = render({
            folders: [{ ...FOLDERS[0], color: null }],
            notes: [],
        });

        const styled = wrapper
            .findAll("svg")
            .filter((svg) => (svg.attributes("style") ?? "").includes("color"));

        expect(styled).toHaveLength(0);
    });

    /**
     * The hover preview shows the rendering, not the source: that is what
     * sets it apart from the cards' excerpt, which is flattened text.
     */
    describe("l'aperçu au survol", () => {
        beforeEach(() => {
            vi.useFakeTimers();
            window.matchMedia = vi.fn(() => ({ matches: true }));
        });

        afterEach(() => vi.useRealTimers());

        function renderWithShow(show) {
            return render({ notesApi: { ...apis().notesApi, show } });
        }

        it("renders the note under the cursor, once the cursor has settled", async () => {
            const show = vi.fn().mockResolvedValue({
                ok: true,
                payload: { note: { content: "## Repérage" } },
            });
            const wrapper = renderWithShow(show);

            await wrapper.findAll("article").at(-1).trigger("mouseenter");
            await vi.advanceTimersByTimeAsync(600);
            await flushPromises();

            expect(show).toHaveBeenCalled();
            expect(document.body.innerHTML).toContain("Repérage");
            // The rendering, not the source: the markdown heading became an h2.
            expect(document.body.innerHTML).toContain("<h2");
        });

        it("says nothing while the cursor only passes by", async () => {
            const show = vi.fn();
            const wrapper = renderWithShow(show);

            const card = wrapper.findAll("article").at(-1);
            await card.trigger("mouseenter");
            await vi.advanceTimersByTimeAsync(200);
            await card.trigger("mouseleave");
            await vi.advanceTimersByTimeAsync(600);

            expect(show).not.toHaveBeenCalled();
        });
    });

    /**
     * Clicking a tag is the gesture one tries on seeing it, and it existed
     * nowhere since the redesign.
     */
    it("shows a tag's notes when its badge is clicked, wherever they are filed", async () => {
        const wrapper = render();

        const badge = wrapper
            .findAll("button")
            .find((b) => b.attributes("title")?.includes("library.tag.filter"));

        expect(badge, "l'étiquette se clique").toBeTruthy();

        await badge.trigger("click");

        // The "Clients" folder disappears: a tag cuts across the filing, and
        // a folder does not carry one.
        const cards = wrapper.findAll("article").map((one) => one.text());

        expect(cards.some((text) => text.includes("Clients"))).toBe(false);
        expect(cards.some((text) => text.includes("À la racine"))).toBe(true);
        expect(wrapper.text()).toContain("essai");
    });

    /**
     * The mosaic shows a thumbnail of the note, not a sentence: the
     * rendering - a heading, a list - is what makes a note recognisable.
     */
    it("draws the first lines as they will read, not as flat text", () => {
        const wrapper = render();
        const thumb = wrapper.find(".note-thumb");

        expect(thumb.exists()).toBe(true);
        expect(thumb.html()).toContain("<h2");
        expect(thumb.html()).toContain("<li");
    });

    it("keeps the thumbnail to the mosaic", async () => {
        const wrapper = render();

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.view.list",
            )
            .trigger("click");

        expect(wrapper.find(".note-thumb").exists()).toBe(false);
    });

    /**
     * "What has left my place": what lives in a space other than one's own.
     * Answering it by going through the cards one by one would be absurd.
     */
    describe("le filtre de visibilité", () => {
        /**
         * The bar's button, not the chip's cross: both carry a title that
         * starts the same way, and targeting by prefix caught the cross,
         * which is higher in the document.
         */
        function filtre(wrapper) {
            return wrapper.findAll("button").find((b) => {
                const titre = b.attributes("title") ?? "";

                return (
                    titre.includes("library.visibility") &&
                    !titre.includes("clear")
                );
            });
        }

        it("cycles through everything, shared, then private", async () => {
            const wrapper = render({
                personalSpaceId: 1,
                folders: [{ ...FOLDERS[0], spaceId: 7 }],
                notes: [
                    {
                        id: 21,
                        folderId: null,
                        title: "Privée",
                        tags: [],
                        spaceId: 1,
                    },
                ],
            });

            expect(wrapper.text()).toContain("Clients");
            expect(wrapper.text()).toContain("Privée");

            await filtre(wrapper).trigger("click");

            expect(wrapper.text()).toContain("Clients");
            expect(wrapper.text(), "une note non partagée sort").not.toContain(
                "Privée",
            );

            await filtre(wrapper).trigger("click");

            expect(
                wrapper.text(),
                "et le partagé sort à son tour",
            ).not.toContain("Clients");
            expect(wrapper.text()).toContain("Privée");

            await filtre(wrapper).trigger("click");

            expect(wrapper.text()).toContain("Clients");
            expect(wrapper.text()).toContain("Privée");
        });

        it("marks what is shared, without opening a menu", () => {
            const wrapper = render({
                personalSpaceId: 1,
                folders: [{ ...FOLDERS[0], spaceId: 7 }],
                notes: [],
            });

            const marque = wrapper
                .findAll("svg")
                .filter((svg) =>
                    svg.attributes("title")?.includes("shared.badge"),
                );

            expect(marque.length).toBeGreaterThan(0);
        });
    });

    it("draws a table when the list view is picked", async () => {
        const wrapper = render();

        expect(wrapper.find("table").exists()).toBe(false);

        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.view.list",
            )
            .trigger("click");

        expect(wrapper.find("table").exists()).toBe(true);
    });

    it("filters what is on screen on what the reader typed", async () => {
        const wrapper = render();

        // The search is folded into a magnifier as long as nobody searches.
        await wrapper
            .findAll("button")
            .find(
                (b) =>
                    b.attributes("title") ===
                    "notes.markdown.library.search_placeholder",
            )
            .trigger("click");

        await wrapper.find("input").setValue("racine");
        await flushPromises();

        expect(wrapper.text()).toContain("À la racine");
        expect(wrapper.text()).not.toContain("Clients");
    });
});

/** Opens selection mode through its button, like the user. */
async function startSelecting(wrapper) {
    await wrapper
        .findAll("button")
        .find((button) =>
            (button.attributes("title") ?? "").endsWith("library.select"),
        )
        .trigger("click");
}
