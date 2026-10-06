import { describe, expect, it } from "vitest";
import { nextTick, ref } from "vue";
import { useNoteTree } from "./useNoteTree.js";

/**
 * The tree, which now only carries folders.
 *
 * It used to carry notes, back when a note with children stood in for a
 * folder. What these cases keep from the old suite is the rule that matters:
 * an ancestor of a result stays shown, otherwise the result has no branch
 * left to hang from.
 */
function collectIds(nodes) {
    return nodes.flatMap((node) => [
        node.id,
        ...collectIds(node.children ?? []),
    ]);
}

const FOLDERS = [
    { id: 1, parentId: null, name: "Clients", position: 0 },
    { id: 2, parentId: 1, name: "Studio Lumen", position: 0 },
    { id: 3, parentId: 1, name: "Cabinet Verrier", position: 1 },
    { id: 4, parentId: null, name: "Personnel", position: 1 },
];

describe("useNoteTree", () => {
    it("builds a tree from the flat list", () => {
        const { tree } = useNoteTree(ref(FOLDERS));

        expect(tree.value).toHaveLength(2);
        expect(tree.value[0].id).toBe(1);
        expect(collectIds(tree.value[0].children)).toEqual([2, 3]);
    });

    it("filters folders by case-insensitive name substring", () => {
        const { tree } = useNoteTree(ref(FOLDERS), ref("lumen"));

        expect(collectIds(tree.value)).toEqual([1, 2]);
        expect(tree.value[0].matched).toBe(false);
        expect(tree.value[0].children[0].matched).toBe(true);
    });

    it("keeps ancestors of a match as unmatched carriers", () => {
        const { tree } = useNoteTree(ref(FOLDERS), ref("verrier"));

        expect(tree.value).toHaveLength(1);
        expect(tree.value[0].id).toBe(1);
        expect(tree.value[0].matched).toBe(false);
        expect(tree.value[0].children).toHaveLength(1);
        expect(tree.value[0].children[0].id).toBe(3);
    });

    it("returns an empty tree when nothing matches", () => {
        const { tree } = useNoteTree(ref(FOLDERS), ref("introuvable"));

        expect(tree.value).toEqual([]);
    });

    /**
     * Expanding is for seeing what is filed: the notes are in the tree,
     * after the subfolders of their folder.
     */
    it("files the notes under their folder, in their rank among the sub-folders", () => {
        // The ranks the migration gave: folders first, then the notes,
        // after them.
        const notes = [
            { id: 11, folderId: 1, title: "Devis", position: 2 },
            { id: 12, folderId: null, title: "À la racine", position: 2 },
        ];

        const { tree } = useNoteTree(ref(FOLDERS), null, ref(notes));

        const clients = tree.value[0];
        expect(clients.kind).toBe("folder");
        expect(clients.children.map((child) => child.kind)).toEqual([
            "folder",
            "folder",
            "note",
        ]);

        // What is filed nowhere closes the list, next to the folders.
        expect(tree.value.at(-1)).toMatchObject({ kind: "note", id: 12 });
    });

    /**
     * Folders and notes share a single order: a note filed before a folder
     * comes before it, which the tree used to refuse.
     */
    it("puts a note before a folder when its rank says so", () => {
        const notes = [{ id: 11, folderId: 1, title: "Tâches", position: 0 }];

        const { tree } = useNoteTree(ref(FOLDERS), null, ref(notes));

        // Rank 0 for the note as for "Studio Lumen": on a tie, the folder
        // first, then the note before "Cabinet Verrier" (rank 1).
        expect(
            tree.value[0].children.map((child) => `${child.kind}:${child.id}`),
        ).toEqual(["folder:2", "note:11", "folder:3"]);
    });

    it("keeps a note's folder on screen when the note is the match", () => {
        const notes = [{ id: 11, folderId: 3, title: "Tarte" }];

        const { tree } = useNoteTree(ref(FOLDERS), ref("tarte"), ref(notes));

        expect(tree.value).toHaveLength(1);
        expect(tree.value[0]).toMatchObject({ id: 1, matched: false });
        expect(collectIds(tree.value)).toContain(11);
    });

    /** A note's text is on the server: it arrives through its ids. */
    it("takes the content matches the server resolved", () => {
        const notes = [{ id: 11, folderId: 1, title: "Sans rapport" }];

        const { tree } = useNoteTree(
            ref(FOLDERS),
            ref("facture"),
            ref(notes),
            ref(new Set([11])),
        );

        expect(collectIds(tree.value)).toContain(11);
    });

    it("rebuilds when the list changes", async () => {
        const folders = ref(FOLDERS);
        const { tree } = useNoteTree(folders);

        folders.value = [{ id: 9, parentId: null, name: "Neuf", position: 0 }];
        // `watchEffect` runs before the next render, not on assignment: the
        // rebuild is what the tree promises, and it happens a tick later.
        await nextTick();

        expect(collectIds(tree.value)).toEqual([9]);
    });
});
