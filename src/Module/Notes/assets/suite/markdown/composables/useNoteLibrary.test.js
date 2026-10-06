import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ref } from "vue";
import { useNoteLibrary } from "./useNoteLibrary.js";

/**
 * What the library shows, and in what order.
 *
 * The two rules that must never move are here: folders come before notes
 * whatever the sort, and entering a folder writes a real address rather
 * than reloading the page.
 */
const FOLDERS = [
    {
        id: 1,
        parentId: null,
        name: "Clients",
        position: 1,
        updatedAt: "2026-09-01T10:00:00+00:00",
        createdAt: "2026-01-01T10:00:00+00:00",
    },
    {
        id: 2,
        parentId: 1,
        name: "Studio Lumen",
        position: 0,
        updatedAt: "2026-09-10T10:00:00+00:00",
        createdAt: "2026-02-01T10:00:00+00:00",
    },
    {
        id: 3,
        parentId: null,
        name: "Archives",
        position: 0,
        updatedAt: "2026-08-01T10:00:00+00:00",
        createdAt: "2026-03-01T10:00:00+00:00",
    },
];

const NOTES = [
    {
        id: 11,
        folderId: null,
        title: "Zèbre",
        position: 0,
        updatedAt: "2026-09-20T10:00:00+00:00",
        createdAt: "2026-04-01T10:00:00+00:00",
    },
    {
        id: 12,
        folderId: null,
        title: "Abricot",
        position: 1,
        updatedAt: "2026-09-05T10:00:00+00:00",
        createdAt: "2026-05-01T10:00:00+00:00",
    },
    {
        id: 13,
        folderId: 1,
        title: "Studio",
        position: 0,
        updatedAt: "2026-09-15T10:00:00+00:00",
        createdAt: "2026-06-01T10:00:00+00:00",
    },
    // Two levels down: what flat mode must bring up and what filed mode must
    // leave behind its folder.
    {
        id: 14,
        folderId: 2,
        title: "Lumen",
        position: 0,
        updatedAt: "2026-09-18T10:00:00+00:00",
        createdAt: "2026-07-01T10:00:00+00:00",
    },
];

function build(initialFolderId = null) {
    return useNoteLibrary({
        folders: ref(FOLDERS),
        notes: ref(NOTES),
        initialFolderId,
        breadcrumb: [],
        urlFor: (id) => `/suite/notes/markdown/folder/${id}`,
        rootUrl: "/suite/notes/markdown",
    });
}

beforeEach(() => {
    window.localStorage.clear();
    window.history.replaceState({}, "", "/suite/notes/markdown");
});

afterEach(() => vi.restoreAllMocks());

describe("useNoteLibrary", () => {
    it("shows what the current folder holds, and nothing else", () => {
        const root = build();

        expect(root.folders.value.map((f) => f.id).sort()).toEqual([1, 3]);
        expect(root.notes.value.map((n) => n.id).sort()).toEqual([11, 12]);

        const inside = build(1);

        expect(inside.folders.value.map((f) => f.id)).toEqual([2]);
        expect(inside.notes.value.map((n) => n.id)).toEqual([13]);
    });

    it("sorts by name, in both directions", () => {
        const library = build();

        library.setSort("name");
        library.toggleDirection(); // desc by default, so asc here

        expect(library.direction.value).toBe("asc");
        expect(library.folders.value.map((f) => f.name)).toEqual([
            "Archives",
            "Clients",
        ]);
        expect(library.notes.value.map((n) => n.title)).toEqual([
            "Abricot",
            "Zèbre",
        ]);

        library.toggleDirection();

        expect(library.notes.value.map((n) => n.title)).toEqual([
            "Zèbre",
            "Abricot",
        ]);
    });

    it("sorts by date, most recent first by default", () => {
        const library = build();

        expect(library.sort.value).toBe("updated");
        expect(library.direction.value).toBe("desc");
        expect(library.notes.value.map((n) => n.id)).toEqual([11, 12]);
    });

    /**
     * The direction button looked broken on Axel's data set: his three notes
     * carried the same second, so the sort by date declared them tied and
     * reversing changed nothing visible.
     */
    it("still reverses something when the dates are all equal", () => {
        const sameSecond = "2026-09-23T06:59:00+00:00";
        const notes = ref([
            {
                id: 21,
                folderId: null,
                title: "Alpha",
                position: 0,
                updatedAt: sameSecond,
            },
            {
                id: 22,
                folderId: null,
                title: "Bravo",
                position: 1,
                updatedAt: sameSecond,
            },
            {
                id: 23,
                folderId: null,
                title: "Charlie",
                position: 2,
                updatedAt: sameSecond,
            },
        ]);

        const library = useNoteLibrary({
            folders: ref([]),
            notes,
            initialFolderId: null,
            breadcrumb: [],
            urlFor: (id) => `/f/${id}`,
            rootUrl: "/",
        });

        const descending = library.notes.value.map((note) => note.title);
        library.toggleDirection();
        const ascending = library.notes.value.map((note) => note.title);

        expect(ascending).toEqual([...descending].reverse());
        expect(ascending).toEqual(["Alpha", "Bravo", "Charlie"]);
    });

    it("keeps the manual order when asked for it", () => {
        const library = build();

        library.setSort("manual");
        library.toggleDirection();

        expect(library.notes.value.map((n) => n.id)).toEqual([11, 12]);
    });

    /**
     * The notebook, as if there were no filing.
     */
    describe("à plat", () => {
        it("shows every note of here and below, and no folder", () => {
            const library = build();

            library.toggleFlat();

            expect(library.flat.value).toBe(true);
            expect(library.folders.value).toEqual([]);
            expect(library.notes.value.map((n) => n.id).sort()).toEqual([
                11, 12, 13, 14,
            ]);
        });

        it("stays inside the folder it is opened from", () => {
            const library = build(1);

            library.toggleFlat();

            // 13 is in "Clients", 14 in "Studio Lumen" which is inside it;
            // 11 and 12 are at the root and stay out.
            expect(library.notes.value.map((n) => n.id).sort()).toEqual([
                13, 14,
            ]);
        });

        it("names the folder a note comes from", () => {
            const library = build();

            expect(library.folderNameOf(2)).toBe("Studio Lumen");
            expect(library.folderNameOf(null)).toBeNull();
        });

        /**
         * Two notes from two folders have no common position: flattened,
         * manual order would rank by a number that means nothing from one
         * folder to the next.
         */
        it("leaves the manual order behind", () => {
            const library = build();

            library.setSort("manual");
            library.toggleFlat();

            expect(library.sort.value).toBe("updated");

            library.setSort("manual");

            expect(library.sort.value).toBe("updated");
        });

        it("remembers the scope for the next visit", () => {
            build().toggleFlat();

            expect(build().flat.value).toBe(true);
        });

        it("survives a cycle in the folder chain rather than hanging", () => {
            const library = useNoteLibrary({
                folders: ref([
                    { id: 1, parentId: 2, name: "A", position: 0 },
                    { id: 2, parentId: 1, name: "B", position: 0 },
                ]),
                notes: ref([{ id: 31, folderId: 2, title: "Dedans" }]),
                initialFolderId: 1,
                breadcrumb: [],
                urlFor: (id) => `/f/${id}`,
                rootUrl: "/",
            });

            library.toggleFlat();

            expect(library.notes.value.map((n) => n.id)).toEqual([31]);
        });
    });

    /**
     * A tag cuts across the filing: it is the question one asks when
     * clicking it.
     */
    describe("une étiquette", () => {
        function withTags() {
            return useNoteLibrary({
                folders: ref(FOLDERS),
                notes: ref([
                    {
                        id: 41,
                        folderId: null,
                        title: "Racine",
                        tags: ["photo"],
                    },
                    {
                        id: 42,
                        folderId: 2,
                        title: "Dedans",
                        tags: ["photo", "essai"],
                    },
                    { id: 43, folderId: 1, title: "Sans", tags: [] },
                ]),
                initialFolderId: 1,
                breadcrumb: [],
                urlFor: (id) => `/f/${id}`,
                rootUrl: "/",
            });
        }

        it("looks through the whole notebook, not just the open folder", () => {
            const library = withTags();

            library.setTag("photo");

            expect(library.notes.value.map((n) => n.id).sort()).toEqual([
                41, 42,
            ]);
            expect(library.folders.value).toEqual([]);
        });

        it("gives the folder back when it is cleared", () => {
            const library = withTags();

            library.setTag("photo");
            library.setTag(null);

            expect(library.currentFolderId.value).toBe(1);
            expect(library.notes.value.map((n) => n.id)).toEqual([43]);
            expect(library.folders.value.map((f) => f.id)).toEqual([2]);
        });

        it("reads an empty string as no tag at all", () => {
            const library = withTags();

            library.setTag("");

            expect(library.tag.value).toBeNull();
        });
    });

    it("remembers the view and the sort for the next visit", () => {
        const first = build();

        first.setView("list");
        first.setSort("name");

        const second = build();

        expect(second.view.value).toBe("list");
        expect(second.sort.value).toBe("name");
    });

    it("refuses a view or a sort it does not know", () => {
        const library = build();

        library.setView("carousel");
        library.setSort("colour");

        expect(library.view.value).toBe("mosaic");
        expect(library.sort.value).toBe("updated");
    });

    it("writes the folder's own address when entering it", () => {
        const library = build();

        library.openFolder(1);

        expect(library.currentFolderId.value).toBe(1);
        expect(window.location.pathname).toBe("/suite/notes/markdown/folder/1");

        library.openFolder(null);

        expect(window.location.pathname).toBe("/suite/notes/markdown");
    });

    /** Zero is not a folder: it is a `Number(null)` along the way. */
    it("reads a zero as the root rather than as a folder", () => {
        const library = build(1);

        library.openFolder(0);

        expect(library.currentFolderId.value).toBeNull();
        expect(window.location.pathname).toBe("/suite/notes/markdown");
    });

    it("draws the chain from the root to here", () => {
        const library = build(2);

        expect(library.path.value.map((crumb) => crumb.name)).toEqual([
            "Clients",
            "Studio Lumen",
        ]);
    });

    it("follows the browser's back button", () => {
        const library = build(2);

        library.onPopState({ state: { folderId: 1 } });
        expect(library.currentFolderId.value).toBe(1);

        // Without state - a history entry written elsewhere - the address is
        // what counts.
        window.history.replaceState({}, "", "/suite/notes/markdown/folder/3");
        library.onPopState({});
        expect(library.currentFolderId.value).toBe(3);
    });

    it("survives a cycle in the folder chain rather than hanging", () => {
        const folders = ref([
            { id: 1, parentId: 2, name: "A", position: 0 },
            { id: 2, parentId: 1, name: "B", position: 0 },
        ]);

        const library = useNoteLibrary({
            folders,
            notes: ref([]),
            initialFolderId: 1,
            breadcrumb: [],
            urlFor: (id) => `/f/${id}`,
            rootUrl: "/",
        });

        expect(library.path.value.map((crumb) => crumb.id)).toEqual([2, 1]);
    });
});
