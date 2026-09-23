import { describe, expect, it } from "vitest";
import { nextTick, ref } from "vue";
import { useNoteTree } from "./useNoteTree.js";

/**
 * L'arborescence, qui ne porte plus que des dossiers.
 *
 * Elle portait des notes, du temps où une note qui avait des enfants tenait
 * lieu de dossier. Ce que ces cas gardent de l'ancienne suite, c'est la règle
 * qui compte : un ancêtre d'un résultat reste affiché, sans quoi le résultat
 * n'a plus de branche à laquelle se rattacher.
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
     * Le dépliage sert à voir ce qui est rangé : les notes sont dans
     * l'arbre, à la suite des sous-dossiers de leur dossier.
     */
    it("files the notes under their folder, after the sub-folders", () => {
        const notes = [
            { id: 11, folderId: 1, title: "Devis" },
            { id: 12, folderId: null, title: "À la racine" },
        ];

        const { tree } = useNoteTree(ref(FOLDERS), null, ref(notes));

        const clients = tree.value[0];
        expect(clients.kind).toBe("folder");
        expect(clients.children.map((child) => child.kind)).toEqual([
            "folder",
            "folder",
            "note",
        ]);

        // Ce qui n'est rangé nulle part ferme la liste, à côté des dossiers.
        expect(tree.value.at(-1)).toMatchObject({ kind: "note", id: 12 });
    });

    it("keeps a note's folder on screen when the note is the match", () => {
        const notes = [{ id: 11, folderId: 3, title: "Tarte" }];

        const { tree } = useNoteTree(ref(FOLDERS), ref("tarte"), ref(notes));

        expect(tree.value).toHaveLength(1);
        expect(tree.value[0]).toMatchObject({ id: 1, matched: false });
        expect(collectIds(tree.value)).toContain(11);
    });

    /** Le texte d'une note est au serveur : il arrive par ses identifiants. */
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
