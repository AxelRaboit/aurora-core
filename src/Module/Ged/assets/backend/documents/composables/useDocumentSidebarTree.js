import { ref, computed } from "vue";
import {
    buildFolderTree,
    flattenFolders as flattenFoldersGeneric,
} from "@/shared/utils/tree/folderTree.js";

const FAVOURITE_KEY = "aurora-ged-favourite-folders";
const COLLAPSED_KEY = "aurora-ged-collapsed-folders";

function loadIdSet(key) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? new Set(JSON.parse(raw)) : new Set();
    } catch {
        return new Set();
    }
}

function persistIdSet(key, set) {
    try {
        localStorage.setItem(key, JSON.stringify([...set]));
    } catch {
        /* ignore */
    }
}

/** A flat option list whose indent shows the nesting. */
export function withDepthLabel(list) {
    return list.map((folder) => ({
        ...folder,
        displayLabel: "\u00a0\u00a0".repeat(folder.depth ?? 0) + folder.name,
    }));
}

/**
 * Position first, name only to break a tie.
 *
 * `buildFolderTree` sorts alphabetically unless told otherwise, and that quietly
 * threw away the order the reader had just dragged into place: the server stored
 * it, the next render sorted it back by name, and the whole gesture looked like
 * it had done nothing. The deleted folders page had this comparator; the panel
 * inherited its job and has to inherit this too.
 */
const byPosition = (a, b) =>
    (a.position ?? 0) - (b.position ?? 0) || a.name.localeCompare(b.name);

/**
 * GED sidebar tree mirroring Media's: tree + flat list (collapse-aware),
 * favourites and per-folder document count. Favourites + collapsed state are
 * persisted to localStorage so they survive reloads.
 */
export function useDocumentSidebarTree(folders, currentFolderId) {
    const folderTree = computed(() =>
        buildFolderTree(folders.value, byPosition),
    );

    const collapsedFolderIds = ref(loadIdSet(COLLAPSED_KEY));
    const favouriteFolderIds = ref(loadIdSet(FAVOURITE_KEY));

    function toggleFavourite(folderId) {
        const next = new Set(favouriteFolderIds.value);
        if (next.has(folderId)) next.delete(folderId);
        else next.add(folderId);
        favouriteFolderIds.value = next;
        persistIdSet(FAVOURITE_KEY, next);
    }

    function toggleCollapse(folderId) {
        const next = new Set(collapsedFolderIds.value);
        if (next.has(folderId)) next.delete(folderId);
        else next.add(folderId);
        collapsedFolderIds.value = next;
        persistIdSet(COLLAPSED_KEY, next);
    }

    function withDocumentCount(nodes) {
        return nodes.map((node) => ({
            ...node,
            documentCount: node.documentCount ?? 0,
        }));
    }

    const flatFolders = computed(() =>
        withDocumentCount(
            flattenFoldersGeneric(
                folderTree.value,
                0,
                collapsedFolderIds.value,
            ),
        ),
    );
    const allFlatFolders = computed(() =>
        withDocumentCount(flattenFoldersGeneric(folderTree.value)),
    );

    const favouriteFolders = computed(() =>
        folders.value.filter((f) => favouriteFolderIds.value.has(f.id)),
    );

    /**
     * The same folders as a flat `<select>` list, nesting shown by indent.
     *
     * Derived from the tree and nothing else, which is why it lives here rather
     * than beside the folder CRUD it used to sit next to: the document form's
     * folder picker needs it on a page that no longer creates folders at all.
     */
    const folderEditOptions = computed(() =>
        withDepthLabel(allFlatFolders.value),
    );

    const currentFolder = computed(
        () => folders.value.find((f) => f.id === currentFolderId.value) ?? null,
    );

    const breadcrumbs = computed(() => {
        const chain = [];
        let current = currentFolder.value;
        while (current) {
            chain.unshift(current);
            current =
                folders.value.find((f) => f.id === current.parentId) ?? null;
        }
        return chain;
    });

    return {
        folderEditOptions,
        folderTree,
        flatFolders,
        allFlatFolders,
        currentFolder,
        breadcrumbs,
        collapsedFolderIds,
        toggleCollapse,
        favouriteFolderIds,
        toggleFavourite,
        favouriteFolders,
    };
}
