import { ref, watchEffect } from "vue";
import { buildTree as buildHierarchicalTree } from "@/shared/composables/tree/useHierarchicalTree.js";

/**
 * Build a hierarchical tree from a flat list of notes (sorted by position).
 * Each node looks like { id, parentId, title, ..., children: [...] }.
 *
 * Exposes a *writable* `tree` ref so VueDraggable can mutate the children
 * arrays directly. A watcher rebuilds the tree whenever any input ref
 * changes - that way `refreshList()` from the server still wins over
 * any local DnD mutation.
 *
 * Filtering rules:
 *   - `queryRef` (free-text search) matches when the node's title, any
 *     of its tags, OR its server-resolved content match the query
 *     substring (case-insensitive). The content-match comes from
 *     `contentMatchIdsRef`, a Set populated by a debounced call to the
 *     `/search` backend endpoint (so we don't need to ship every
 *     decrypted note's body to the browser).
 *   - `selectedTagsRef` (pill filter) keeps only nodes carrying any
 *     selected tag (OR semantics).
 * Ancestors of matching nodes are preserved so the leaves stay attached
 * to the tree.
 *
 * Each kept node carries a `matched` flag for styling.
 */
export function useNoteTree(
    notesRef,
    queryRef = null,
    selectedTagsRef = null,
    contentMatchIdsRef = null,
) {
    const tree = ref([]);

    watchEffect(() => {
        const query = (queryRef?.value ?? "").trim().toLowerCase();
        const tags = selectedTagsRef?.value ?? [];
        const contentIds = contentMatchIdsRef?.value ?? null;
        const fullTree = buildHierarchicalTree(notesRef.value).map(decorate);
        const hasFilter = query !== "" || tags.length > 0;
        tree.value = hasFilter
            ? filterTree(fullTree, query, tags, contentIds)
            : fullTree;
    });

    /** Annotate every node with `matched: true` for consistent template logic. */
    function decorate(node) {
        return {
            ...node,
            matched: true,
            children: (node.children ?? []).map(decorate),
        };
    }

    /**
     * Free-text query match: title substring, any tag substring, OR
     * an id present in `contentIds` (resolved server-side). Returns
     * true for an empty query so the tag-pill filter can run alone.
     */
    function matchesQuery(node, query, contentIds) {
        if (query === "") return true;
        const title = (node.title ?? "").toLowerCase();
        if (title.includes(query)) return true;
        const nodeTags = node.tags ?? [];
        if (nodeTags.some((t) => String(t).toLowerCase().includes(query))) {
            return true;
        }
        if (contentIds && contentIds.has(node.id)) return true;
        return false;
    }

    /** Tag-pill filter (different from the free-text query). OR semantics. */
    function matchesTags(node, tags) {
        if (tags.length === 0) return true;
        const noteTags = node.tags ?? [];
        return tags.some((tag) => noteTags.includes(tag));
    }

    function filterTree(nodes, query, tags, contentIds) {
        const kept = [];
        for (const node of nodes) {
            const children = filterTree(node.children, query, tags, contentIds);
            const selfMatch =
                matchesQuery(node, query, contentIds) &&
                matchesTags(node, tags);
            if (selfMatch || children.length > 0) {
                kept.push({ ...node, matched: selfMatch, children });
            }
        }
        return kept;
    }

    return { tree };
}
