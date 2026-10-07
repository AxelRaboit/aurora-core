import { ref, watchEffect } from "vue";
import { buildTree as buildHierarchicalTree } from "@/shared/composables/tree/useHierarchicalTree.js";
import { compareSiblings } from "./noteSiblingOrder.js";

/**
 * The menu tree: folders, and what they contain.
 *
 * For one version it only carried folders, which was half an answer: one
 * saw the filing without seeing what is filed, and reaching a note required
 * opening the folder in the library. A folder expands here and shows its
 * notes, as in any file explorer - the difference with the old tree being
 * that a note is a leaf, never a container.
 *
 * Each node carries its `kind`, `folder` or `note`, because the two look
 * alike on screen and do not do the same thing: one opens, the other is
 * read. Ids overlap from one table to the other, so the render keys are
 * `kind:id`.
 *
 * **The filter keeps the carriers.** A folder whose name does not match
 * stays shown when it contains a result, otherwise the result would have no
 * branch left to hang from. `contentMatchIdsRef` brings the notes found by
 * their text, which the browser does not have: the bodies are encrypted and
 * stay on the server.
 */
function siblingOrder(nodes) {
    return [...nodes].sort(compareSiblings);
}

export function useNoteTree(
    foldersRef,
    queryRef = null,
    notesRef = null,
    contentMatchIdsRef = null,
) {
    const tree = ref([]);

    watchEffect(() => {
        const query = (queryRef?.value ?? "").trim().toLowerCase();
        const notes = notesRef?.value ?? [];
        const contentIds = contentMatchIdsRef?.value ?? null;

        const notesByFolder = new Map();
        for (const note of notes) {
            const key =
                null === note.folderId || undefined === note.folderId
                    ? 0
                    : Number(note.folderId);

            if (!notesByFolder.has(key)) notesByFolder.set(key, []);

            notesByFolder.get(key).push({
                ...note,
                kind: "note",
                key: `note:${note.id}`,
                children: [],
                matched: true,
            });
        }

        const folders = buildHierarchicalTree(foldersRef.value).map((node) =>
            decorate(node, notesByFolder),
        );

        // Folders and notes of the same level share a single order: a note
        // can come before a folder, as in Craft or Notion.
        const full = siblingOrder([
            ...folders,
            ...(notesByFolder.get(0) ?? []),
        ]);

        tree.value = "" === query ? full : filterTree(full, query, contentIds);
    });

    function decorate(node, notesByFolder) {
        const children = (node.children ?? []).map((child) =>
            decorate(child, notesByFolder),
        );

        return {
            ...node,
            kind: "folder",
            key: `folder:${node.id}`,
            matched: true,
            children: siblingOrder([
                ...children,
                ...(notesByFolder.get(Number(node.id)) ?? []),
            ]),
        };
    }

    function matches(node, query, contentIds) {
        if ("folder" === node.kind) {
            return String(node.name ?? "")
                .toLowerCase()
                .includes(query);
        }

        if (
            String(node.title ?? "")
                .toLowerCase()
                .includes(query)
        )
            return true;

        if (contentIds?.has(Number(node.id))) return true;

        return (node.tags ?? []).some((tag) =>
            String(tag).toLowerCase().includes(query),
        );
    }

    function filterTree(nodes, query, contentIds) {
        const kept = [];

        for (const node of nodes) {
            const children = filterTree(node.children ?? [], query, contentIds);
            const selfMatch = matches(node, query, contentIds);

            if (selfMatch || children.length > 0) {
                kept.push({ ...node, matched: selfMatch, children });
            }
        }

        return kept;
    }

    return { tree };
}

/**
 * The folders of a filtered tree, to expand them all.
 *
 * A search that leaves the branches closed shows nothing: what it found is
 * precisely what is folded.
 *
 * @returns {Set<number>} the ids of the folders met
 */
export function folderIdsIn(nodes) {
    const ids = new Set();

    const walk = (list) => {
        for (const node of list) {
            if ("folder" !== node.kind) continue;

            ids.add(Number(node.id));
            walk(node.children ?? []);
        }
    };

    walk(nodes);

    return ids;
}
