import { describe, expect, it } from "vitest";
import { buildFolderTree, flattenFolders } from "./folderTree.js";

const folders = [
    { id: 3, parentId: 1, name: "beta", mediaCount: 2 },
    { id: 1, parentId: null, name: "alpha" },
    { id: 2, parentId: null, name: "gamma" },
    { id: 4, parentId: 1, name: "alpha-child" },
];

describe("buildFolderTree", () => {
    it("returns roots sorted alphabetically", () => {
        const tree = buildFolderTree(folders);
        expect(tree.map((n) => n.name)).toEqual(["alpha", "gamma"]);
    });

    it("nests children under their parent and sorts them", () => {
        const tree = buildFolderTree(folders);
        const alpha = tree.find((n) => n.name === "alpha");
        expect(alpha.children.map((c) => c.name)).toEqual([
            "alpha-child",
            "beta",
        ]);
    });

    it("treats unknown parents as roots", () => {
        const orphan = [{ id: 9, parentId: 99, name: "z" }];
        expect(buildFolderTree(orphan)).toHaveLength(1);
    });
});

describe("flattenFolders", () => {
    it("flattens depth-first with depth and counts", () => {
        const tree = buildFolderTree(folders);
        const flat = flattenFolders(tree);
        expect(flat.map((n) => [n.name, n.depth])).toEqual([
            ["alpha", 0],
            ["alpha-child", 1],
            ["beta", 1],
            ["gamma", 0],
        ]);
    });

    it("propagates mediaCount and childCount", () => {
        const tree = buildFolderTree(folders);
        const flat = flattenFolders(tree);
        const beta = flat.find((n) => n.name === "beta");
        expect(beta.mediaCount).toBe(2);
        const alpha = flat.find((n) => n.name === "alpha");
        expect(alpha.childCount).toBe(2);
    });

    it("hides descendants of folders listed in skipDescendantsOf", () => {
        const tree = buildFolderTree(folders);
        const skip = new Set([1]);
        const flat = flattenFolders(tree, 0, skip);
        expect(flat.map((n) => n.name)).toEqual(["alpha", "gamma"]);
    });
});

describe("folders that point at each other", () => {
    /**
     * Two folders filed inside one another are attached to each other and to
     * nothing else, so neither reaches `roots` - and every screen built on this
     * function stops drawing them, along with everything beneath. The rows are
     * still in the database. A tree that hides them is a tree nobody can use to
     * put them back.
     */
    it("shows a cycle instead of swallowing it", () => {
        const roots = buildFolderTree([
            { id: 1, name: "A", parentId: 2 },
            { id: 2, name: "B", parentId: 1 },
        ]);

        // The loop is broken by lifting one of the two to the root; the other
        // stays its child. Which one is arbitrary and does not matter - what
        // matters is that both are on screen and can be dragged apart.
        expect(roots).toHaveLength(1);
        expect(
            flattenFolders(roots)
                .map((n) => n.id)
                .sort(),
        ).toEqual([1, 2]);
    });

    it("keeps what hangs below a cycle visible too", () => {
        const roots = buildFolderTree([
            { id: 1, name: "A", parentId: 2 },
            { id: 2, name: "B", parentId: 1 },
            { id: 3, name: "C", parentId: 1 },
        ]);

        const ids = flattenFolders(roots).map((n) => n.id);
        expect(ids).toContain(3);
    });

    it("leaves a healthy tree exactly as it was", () => {
        const roots = buildFolderTree([
            { id: 1, name: "A", parentId: null },
            { id: 2, name: "B", parentId: 1 },
        ]);

        expect(roots).toHaveLength(1);
        expect(roots[0].children.map((c) => c.id)).toEqual([2]);
    });
});
