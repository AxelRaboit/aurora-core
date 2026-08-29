import { describe, it, expect } from "vitest";
import { ref } from "vue";
import { useNoteTree } from "./useNoteTree.js";

const flatNotes = [
    { id: 1, parentId: null, title: "Welcome", position: 0, tags: ["intro"] },
    {
        id: 2,
        parentId: 1,
        title: "Getting Started",
        position: 0,
        tags: ["intro", "todo"],
    },
    { id: 3, parentId: 1, title: "Tips", position: 1, tags: [] },
    { id: 4, parentId: null, title: "Tasks", position: 1, tags: ["todo"] },
    { id: 5, parentId: 4, title: "Errands", position: 0, tags: [] },
];

describe("useNoteTree", () => {
    it("builds a hierarchical tree from a flat list", () => {
        const notes = ref(flatNotes);
        const { tree } = useNoteTree(notes);

        expect(tree.value).toHaveLength(2);
        expect(tree.value[0].id).toBe(1);
        expect(tree.value[0].children).toHaveLength(2);
        expect(tree.value[0].children.map((c) => c.id)).toEqual([2, 3]);
        expect(tree.value[1].id).toBe(4);
        expect(tree.value[1].children).toHaveLength(1);
    });

    it("marks every node as matched when no query is given", () => {
        const notes = ref(flatNotes);
        const { tree } = useNoteTree(notes);

        expect(tree.value[0].matched).toBe(true);
        expect(tree.value[0].children[0].matched).toBe(true);
    });

    it("filters nodes by case-insensitive title substring", () => {
        const notes = ref(flatNotes);
        const query = ref("task");
        const { tree } = useNoteTree(notes, query);

        expect(tree.value).toHaveLength(1);
        expect(tree.value[0].id).toBe(4);
        expect(tree.value[0].matched).toBe(true);
    });

    it("keeps ancestors of matching descendants as unmatched carriers", () => {
        const notes = ref(flatNotes);
        const query = ref("errands");
        const { tree } = useNoteTree(notes, query);

        expect(tree.value).toHaveLength(1);
        // Tasks is the ancestor - it should appear but NOT be flagged as matched
        expect(tree.value[0].id).toBe(4);
        expect(tree.value[0].matched).toBe(false);
        // Errands is the actual hit
        expect(tree.value[0].children[0].id).toBe(5);
        expect(tree.value[0].children[0].matched).toBe(true);
    });

    it("returns an empty tree when nothing matches", () => {
        const notes = ref(flatNotes);
        const query = ref("xyzzy");
        const { tree } = useNoteTree(notes, query);

        expect(tree.value).toHaveLength(0);
    });

    it("treats whitespace-only queries as no-query", () => {
        const notes = ref(flatNotes);
        const query = ref("   ");
        const { tree } = useNoteTree(notes, query);

        expect(tree.value).toHaveLength(2);
    });

    it("filters by selected tags (OR semantics) and preserves ancestors", () => {
        const notes = ref(flatNotes);
        const query = ref("");
        const tags = ref(["todo"]);
        const { tree } = useNoteTree(notes, query, tags);

        // Welcome stays as ancestor of Getting Started (tagged "todo");
        // Tasks itself carries the tag.
        expect(tree.value.map((n) => n.id).sort()).toEqual([1, 4]);
        const welcome = tree.value.find((n) => n.id === 1);
        expect(welcome.matched).toBe(false);
        expect(welcome.children.map((c) => c.id)).toEqual([2]);
        expect(welcome.children[0].matched).toBe(true);
    });

    it("combines title query and tag filter", () => {
        const notes = ref(flatNotes);
        const query = ref("tasks");
        const tags = ref(["todo"]);
        const { tree } = useNoteTree(notes, query, tags);

        // Only Tasks satisfies both filters.
        expect(tree.value).toHaveLength(1);
        expect(tree.value[0].id).toBe(4);
    });

    it("returns an empty tree when no note carries a selected tag", () => {
        const notes = ref(flatNotes);
        const query = ref("");
        const tags = ref(["nonexistent"]);
        const { tree } = useNoteTree(notes, query, tags);

        expect(tree.value).toHaveLength(0);
    });

    it("matches a query against any tag substring", () => {
        const notes = ref(flatNotes);
        // "intro" is a tag carried by ids 1 and 2 - no title contains
        // it, so only the tag match drives the result.
        const query = ref("intro");
        const { tree } = useNoteTree(notes, query);

        expect(tree.value).toHaveLength(1);
        expect(tree.value[0].id).toBe(1);
        expect(tree.value[0].matched).toBe(true);
        expect(tree.value[0].children.map((c) => c.id)).toEqual([2]);
    });

    it("includes notes whose ids appear in the contentMatchIds set", () => {
        const notes = ref(flatNotes);
        // "errands" isn't a title or tag substring for note 3, but if
        // the backend reports it as a content match we should keep it.
        const query = ref("errands");
        const tags = ref([]);
        const contentIds = ref(new Set([3]));
        const { tree } = useNoteTree(notes, query, tags, contentIds);

        const ids = collectIds(tree.value);
        expect(ids).toContain(3); // pulled in by content match
        expect(ids).toContain(5); // matched by title "Errands"
    });
});

function collectIds(nodes) {
    const out = [];
    function walk(list) {
        for (const n of list) {
            out.push(n.id);
            walk(n.children ?? []);
        }
    }
    walk(nodes);
    return out;
}
