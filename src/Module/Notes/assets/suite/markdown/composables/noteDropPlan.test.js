import { describe, expect, it } from "vitest";
import { dropZone, isWithin, planDrop } from "./noteDropPlan.js";

const FOLDERS = [
    { id: 1, name: "Micro-entreprise", parentId: null, position: 0 },
    { id: 2, name: "SIGEDI", parentId: null, position: 1 },
    { id: 3, name: "Salariat", parentId: null, position: 2 },
    { id: 4, name: "Comptabilité", parentId: 1, position: 0 },
    { id: 5, name: "Factures", parentId: 4, position: 0 },
];

const NOTES = [
    { id: 10, title: "Mes informations", folderId: 1, position: 0 },
    { id: 11, title: "Liens utiles", folderId: 1, position: 1 },
    { id: 12, title: "Module Compétences", folderId: 3, position: 0 },
    { id: 13, title: "Campus", folderId: 2, position: 0 },
];

// Les frères d'un dossier, dossiers et notes mêlés, dans l'ordre affiché.
const F = (id) => ({ kind: "folder", id });
const N = (id) => ({ kind: "note", id });

const plan = (dragged, target, zone) =>
    planDrop({ dragged, target, zone, folders: FOLDERS, notes: NOTES });

describe("dropZone", () => {
    const rect = { top: 100, height: 40 };

    it("reads a folder in three bands: before, inside, after", () => {
        expect(dropZone(rect, 104, "folder")).toBe("before");
        expect(dropZone(rect, 120, "folder")).toBe("inside");
        expect(dropZone(rect, 136, "folder")).toBe("after");
    });

    /** A note holds nothing, so it only has two halves. */
    it("reads a note in two halves", () => {
        expect(dropZone(rect, 110, "note")).toBe("before");
        expect(dropZone(rect, 130, "note")).toBe("after");
    });
});

describe("isWithin", () => {
    it("sees a folder inside its ancestor, at any depth", () => {
        expect(isWithin(FOLDERS, 1, 5)).toBe(true);
        expect(isWithin(FOLDERS, 2, 5)).toBe(false);
    });

    it("counts a folder as within itself", () => {
        expect(isWithin(FOLDERS, 4, 4)).toBe(true);
    });

    /** A damaged notebook where a folder names itself must not hang the page. */
    it("stops on a cycle instead of looping", () => {
        const looped = [
            { id: 1, parentId: 2 },
            { id: 2, parentId: 1 },
        ];

        expect(isWithin(looped, 9, 1)).toBe(false);
    });
});

describe("planDrop", () => {
    /** The case Axel hit: a note from Salariat onto SIGEDI. */
    it("files a note into a folder, last", () => {
        expect(
            plan({ kind: "note", id: 12 }, { kind: "folder", id: 2 }, "inside"),
        ).toEqual({
            kind: "note",
            id: 12,
            folderId: 2,
            fromFolderId: 3,
            spaceId: null,
            fromSpaceId: null,
            order: [N(13), N(12)],
        });
    });

    it("takes the exact rank of the row it is dropped before", () => {
        expect(
            plan({ kind: "note", id: 11 }, { kind: "note", id: 10 }, "before"),
        ).toMatchObject({
            folderId: 1,
            order: [F(4), N(11), N(10)],
        });
    });

    it("moves a note beside a note of another folder", () => {
        expect(
            plan({ kind: "note", id: 12 }, { kind: "note", id: 10 }, "after"),
        ).toMatchObject({
            folderId: 1,
            fromFolderId: 3,
            order: [F(4), N(10), N(12), N(11)],
        });
    });

    it("brings anything back to the root", () => {
        expect(
            plan(
                { kind: "folder", id: 4 },
                { kind: "folder", id: null },
                "inside",
            ),
        ).toMatchObject({
            folderId: null,
            fromFolderId: 1,
            order: [F(1), F(2), F(3), F(4)],
        });
    });

    /**
     * Folders and notes share one order: a note dropped before a folder
     * stays before it, which the tree used to refuse.
     */
    it("keeps a note before the folder it was dropped before", () => {
        expect(
            plan({ kind: "note", id: 13 }, { kind: "folder", id: 4 }, "before"),
        ).toMatchObject({
            folderId: 1,
            order: [N(13), F(4), N(10), N(11)],
        });
    });

    it("puts a folder after a note when dropped there", () => {
        expect(
            plan({ kind: "folder", id: 4 }, { kind: "note", id: 11 }, "after"),
        ).toMatchObject({
            folderId: 1,
            order: [N(10), N(11), F(4)],
        });
    });

    it("refuses to file a folder inside itself or its own child", () => {
        expect(
            plan(
                { kind: "folder", id: 1 },
                { kind: "folder", id: 5 },
                "inside",
            ),
        ).toBeNull();
        expect(
            plan(
                { kind: "folder", id: 4 },
                { kind: "folder", id: 4 },
                "inside",
            ),
        ).toBeNull();
    });

    it("says nothing when nothing would change", () => {
        expect(
            plan({ kind: "note", id: 11 }, { kind: "note", id: 10 }, "after"),
        ).toBeNull();
        expect(
            plan({ kind: "note", id: 11 }, { kind: "note", id: 11 }, "before"),
        ).toBeNull();
    });

    it("ignores what it does not know", () => {
        expect(
            plan(
                { kind: "note", id: 999 },
                { kind: "folder", id: 2 },
                "inside",
            ),
        ).toBeNull();
        expect(
            plan({ kind: "note", id: 12 }, { kind: "note", id: 999 }, "after"),
        ).toBeNull();
    });
});

describe("planDrop across spaces", () => {
    const folders = [
        { id: 1, name: "Perso", parentId: null, position: 0, spaceId: 1 },
        { id: 2, name: "Équipe", parentId: null, position: 0, spaceId: 7 },
    ];
    const notes = [
        { id: 10, title: "Brouillon", folderId: null, position: 0, spaceId: 1 },
        { id: 11, title: "Procédure", folderId: null, position: 0, spaceId: 7 },
        { id: 12, title: "Charte", folderId: 2, position: 0, spaceId: 7 },
    ];
    const across = (dragged, target, zone) =>
        planDrop({ dragged, target, zone, folders, notes });

    /** Glisser sur l'en-tête d'un espace range à sa racine. */
    it("files at the root of the space whose header it was dropped on", () => {
        expect(
            across(
                { kind: "note", id: 10 },
                { kind: "folder", id: null, spaceId: 7 },
                "inside",
            ),
        ).toMatchObject({
            folderId: null,
            spaceId: 7,
            fromSpaceId: 1,
            order: [F(2), N(11), N(10)],
        });
    });

    /** Un dossier d'un autre espace emmène la note dans cet espace. */
    it("takes the space of the folder it lands in", () => {
        expect(
            across(
                { kind: "note", id: 10 },
                { kind: "folder", id: 2 },
                "inside",
            ),
        ).toMatchObject({ folderId: 2, spaceId: 7, order: [N(12), N(10)] });
    });

    /** La racine d'un espace ne compte pas les notes d'un autre. */
    it("orders the root of a space on its own", () => {
        expect(
            across(
                { kind: "note", id: 11 },
                { kind: "folder", id: null, spaceId: 1 },
                "inside",
            ),
        ).toMatchObject({ spaceId: 1, order: [F(1), N(10), N(11)] });
    });

    /** Revenir à la racine de son propre espace, déjà en place : rien à faire. */
    it("does nothing when the note is already last at that root", () => {
        expect(
            across(
                { kind: "note", id: 10 },
                { kind: "folder", id: null, spaceId: 1 },
                "inside",
            ),
        ).toBeNull();
    });
});
