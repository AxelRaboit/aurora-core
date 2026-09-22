import { ref, watchEffect } from "vue";
import { buildTree as buildHierarchicalTree } from "@/shared/composables/tree/useHierarchicalTree.js";

/**
 * Build a hierarchical tree of folders from the flat list.
 *
 * It used to build a tree of notes, because a note with children stood in for
 * a folder. Folders are their own objects now, so the tree holds places and
 * the library holds what is in them: nine hundred notes were never an
 * arborescence anybody could read, and they are not in this one.
 *
 * Exposes a writable `tree` ref, rebuilt by a watcher whenever the inputs
 * change, so a refreshed list from the server still wins over any local
 * mutation.
 *
 * Filtering: `queryRef` keeps the folders whose name matches the query
 * (case-insensitive substring). Ancestors of a match are preserved so the
 * matched folder stays attached to the tree, and each kept node carries a
 * `matched` flag for styling.
 */
export function useNoteTree(foldersRef, queryRef = null) {
    const tree = ref([]);

    watchEffect(() => {
        const query = (queryRef?.value ?? "").trim().toLowerCase();
        const fullTree = buildHierarchicalTree(foldersRef.value).map(decorate);
        tree.value = "" === query ? fullTree : filterTree(fullTree, query);
    });

    /** Annotate every node with `matched: true` for consistent template logic. */
    function decorate(node) {
        return {
            ...node,
            matched: true,
            children: (node.children ?? []).map(decorate),
        };
    }

    function matchesQuery(node, query) {
        return String(node.name ?? "")
            .toLowerCase()
            .includes(query);
    }

    function filterTree(nodes, query) {
        const kept = [];

        for (const node of nodes) {
            const children = filterTree(node.children, query);
            const selfMatch = matchesQuery(node, query);

            if (selfMatch || children.length > 0) {
                kept.push({ ...node, matched: selfMatch, children });
            }
        }

        return kept;
    }

    return { tree };
}
