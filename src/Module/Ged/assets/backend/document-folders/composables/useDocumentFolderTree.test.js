import { describe, expect, it } from "vitest";
import { ref } from "vue";
import { useDocumentFolderTree } from "@ged/backend/document-folders/composables/useDocumentFolderTree.js";

function makeFolder(id, name, parentId = null, position = 0) {
    return { id, name, parentId, position };
}

describe("useDocumentFolderTree - toggleCollapse", () => {
    it("adds id to collapsedIds when not present", () => {
        const { collapsedIds, toggleCollapse } = useDocumentFolderTree(ref([]));
        toggleCollapse(3);
        expect(collapsedIds.value.has(3)).toBe(true);
    });

    it("removes id from collapsedIds when already present", () => {
        const { collapsedIds, toggleCollapse } = useDocumentFolderTree(ref([]));
        toggleCollapse(3);
        toggleCollapse(3);
        expect(collapsedIds.value.has(3)).toBe(false);
    });

    it("creates a new Set instance on each toggle (immutable update)", () => {
        const { collapsedIds, toggleCollapse } = useDocumentFolderTree(ref([]));
        const before = collapsedIds.value;
        toggleCollapse(5);
        expect(collapsedIds.value).not.toBe(before);
    });

    it("can collapse multiple independent ids", () => {
        const { collapsedIds, toggleCollapse } = useDocumentFolderTree(ref([]));
        toggleCollapse(1);
        toggleCollapse(2);
        expect(collapsedIds.value.has(1)).toBe(true);
        expect(collapsedIds.value.has(2)).toBe(true);
    });
});

describe("useDocumentFolderTree - flatTree", () => {
    it("returns flat list of all folders", () => {
        const items = ref([makeFolder(1, "Alpha"), makeFolder(2, "Beta")]);
        const { flatTree } = useDocumentFolderTree(items);
        expect(flatTree.value).toHaveLength(2);
    });

    it("sorts root folders by position then name", () => {
        const items = ref([
            makeFolder(3, "Gamma", null, 1),
            makeFolder(1, "Alpha", null, 0),
            makeFolder(2, "Beta", null, 1),
        ]);
        const { flatTree } = useDocumentFolderTree(items);
        const names = flatTree.value.map((n) => n.name);
        expect(names[0]).toBe("Alpha");
        // Beta and Gamma share position 1 → alphabetical
        expect(names[1]).toBe("Beta");
        expect(names[2]).toBe("Gamma");
    });

    it("excludes children of collapsed folder", () => {
        const items = ref([makeFolder(1, "Parent"), makeFolder(2, "Child", 1)]);
        const { flatTree, toggleCollapse } = useDocumentFolderTree(items);
        toggleCollapse(1);
        const ids = flatTree.value.map((n) => n.id);
        expect(ids).not.toContain(2);
    });

    it("includes children when parent is not collapsed", () => {
        const items = ref([makeFolder(1, "Parent"), makeFolder(2, "Child", 1)]);
        const { flatTree } = useDocumentFolderTree(items);
        expect(flatTree.value.map((n) => n.id)).toContain(2);
    });
});

describe("useDocumentFolderTree - filteredTree", () => {
    it("returns full tree when search is empty", () => {
        const items = ref([makeFolder(1, "Alpha"), makeFolder(2, "Beta")]);
        const { filteredTree } = useDocumentFolderTree(items);
        expect(filteredTree.value).toHaveLength(2);
    });

    it("filters folders by partial name match (case-insensitive)", () => {
        const items = ref([
            makeFolder(1, "Alpha"),
            makeFolder(2, "Beta"),
            makeFolder(3, "alpha-sub"),
        ]);
        const { search, filteredTree } = useDocumentFolderTree(items);
        search.value = "ALPHA";
        expect(filteredTree.value.map((n) => n.id)).toEqual([1, 3]);
    });

    it("returns empty array when no match", () => {
        const items = ref([makeFolder(1, "Alpha"), makeFolder(2, "Beta")]);
        const { search, filteredTree } = useDocumentFolderTree(items);
        search.value = "zzz";
        expect(filteredTree.value).toHaveLength(0);
    });

    it("trims whitespace from search query", () => {
        const items = ref([makeFolder(1, "Alpha"), makeFolder(2, "Beta")]);
        const { search, filteredTree } = useDocumentFolderTree(items);
        search.value = "  ";
        expect(filteredTree.value).toHaveLength(2);
    });
});
