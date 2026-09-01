import { computed, reactive, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { withDepthLabel } from "./useDocumentSidebarTree.js";

/**
 * Endpoints written here rather than handed in as props.
 *
 * The side menu mounts a panel with no props at all, so there is no view
 * builder upstream to generate these. It is the same call `useImageUpload` and
 * `documentPicker` already make for the same reason.
 */
const FOLDER_CREATE = "/backend/ged/folders/create";
const FOLDER_UPDATE = "/backend/ged/folders/__id__/update";
const FOLDER_DELETE = "/backend/ged/folders/__id__/delete";
const FOLDER_MOVE = "/backend/ged/folders/__id__/move";
const FOLDER_REORDER = "/backend/ged/folders/reorder";
const DOCUMENT_BULK_MOVE = "/backend/ged/documents/bulk-move";

const DOC_MIME = "application/x-aurora-document";
const FOLDER_MIME = "application/x-aurora-document-folder";

/**
 * Creating, renaming, deleting and re-filing folders, from the side menu.
 *
 * This is the aside's work, moved. It used to hang off the documents page and
 * take that page's props, its `navigateTo` and its `reload`; none of the three
 * exist in the menu, and two of them were page concerns anyway. What replaces
 * them is one `onChanged` callback - the panel decides what to tell the page,
 * and on the pages that are not listening the answer is simply nothing.
 *
 * The drag-and-drop reads the same two MIME types the documents page writes on
 * `dragstart`. Those cross from one Vue application to the other for free:
 * `dataTransfer` belongs to the browser, not to either app.
 *
 * @param {object}   options
 * @param {import("vue").Ref}      options.folders         the panel's own list, updated in place
 * @param {import("vue").ComputedRef} options.allFlatFolders for the parent picker
 * @param {Function} options.onChanged called after any successful write
 */
export function useFolderPanelActions({ folders, allFlatFolders, onChanged }) {
    const { t } = useI18n();
    const { request: submitRequest } = useRequest();
    const { request: deleteRequest } = useRequest();
    const { request: moveRequest } = useRequest();

    const folderModal = reactive({
        open: false,
        editing: null,
        errors: {},
        saving: false,
    });
    const folderForm = reactive({ name: "", parentId: null });
    const deletingFolder = ref(null);

    /**
     * Counts come from the documents listing, not from the folder endpoints, so
     * a write would blank every badge if we took the new list as-is. Carry them
     * across; the next listing response corrects them.
     */
    function adoptFolders(next) {
        if (!Array.isArray(next)) return;

        const counts = new Map(
            folders.value.map((folder) => [
                folder.id,
                folder.documentCount ?? 0,
            ]),
        );
        folders.value = next.map((folder) => ({
            ...folder,
            documentCount: counts.get(folder.id) ?? 0,
        }));
    }

    function openCreateFolder(parentId = null) {
        folderModal.editing = null;
        folderModal.errors = {};
        folderForm.name = "";
        folderForm.parentId = parentId;
        folderModal.open = true;
    }

    function openEditFolder(folder) {
        folderModal.editing = folder;
        folderModal.errors = {};
        folderForm.name = folder.name;
        folderForm.parentId = folder.parentId;
        folderModal.open = true;
    }

    async function submitFolder() {
        folderModal.saving = true;
        folderModal.errors = {};
        try {
            const url = folderModal.editing
                ? buildPath(FOLDER_UPDATE, { id: folderModal.editing.id })
                : FOLDER_CREATE;
            const data = await submitRequest(url, { ...folderForm });
            if (!data) return;
            if (!data.success) {
                folderModal.errors = data.errors ?? {};

                return;
            }

            adoptFolders(data.folders);
            toast.success(t("shared.common.saved"));
            folderModal.open = false;
            onChanged?.();
        } finally {
            folderModal.saving = false;
        }
    }

    async function confirmDeleteFolder() {
        const folder = deletingFolder.value;
        if (!folder) return;

        try {
            const data = await deleteRequest(
                buildPath(FOLDER_DELETE, { id: folder.id }),
            );
            if (!data) return;
            if (!data.success) {
                toast.error(t("shared.common.error"));

                return;
            }

            if (Array.isArray(data.folders)) adoptFolders(data.folders);
            else
                folders.value = folders.value.filter((f) => f.id !== folder.id);

            toast.success(t("shared.common.deleted"));
            onChanged?.({
                deletedFolderId: folder.id,
                parentId: folder.parentId ?? null,
            });
        } finally {
            deletingFolder.value = null;
        }
    }

    /** Excludes the folder being edited and its descendants from the parent picker. */
    const folderParentSelectOptions = computed(() => {
        if (!folderModal.editing) return withDepthLabel(allFlatFolders.value);

        const forbidden = new Set([folderModal.editing.id]);
        const addDescendants = (id) => {
            for (const folder of folders.value) {
                if (folder.parentId === id && !forbidden.has(folder.id)) {
                    forbidden.add(folder.id);
                    addDescendants(folder.id);
                }
            }
        };
        addDescendants(folderModal.editing.id);

        return withDepthLabel(
            allFlatFolders.value.filter((f) => !forbidden.has(f.id)),
        );
    });

    /**
     * Where a drag is hovering: `{ id, zone }` with zone `before` | `into` |
     * `after`, the same three bands the dedicated folders page used before it
     * was folded into this panel - reparent in the middle, reorder at the
     * edges.
     *
     * The bands are 40 / 20 / 40 of the row height, so `into` is the narrow
     * one - and it is the row's *height* that decides whether it can be hit,
     * not the column's width. The folders page had `py-3` rows; a plain nav row
     * is half that, which is why the panel's are given a floor of `min-h-8`.
     */
    const dropTarget = ref(null);
    const draggingFolderId = ref(null);

    function dragTypes(event) {
        return event.dataTransfer?.types ?? [];
    }

    function zoneAt(event) {
        const rect = event.currentTarget.getBoundingClientRect();
        const ratio = (event.clientY - rect.top) / rect.height;

        if (ratio < 0.4) return "before";
        if (ratio > 0.6) return "after";

        return "into";
    }

    function onFolderDragStart(event, folder) {
        draggingFolderId.value = folder.id;
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData(FOLDER_MIME, String(folder.id));
    }

    /**
     * Whether `folderId` is `ancestorId` or sits somewhere beneath it.
     *
     * Refused on all three bands, not just the middle: dropping on the edge of
     * a descendant reorders it among its siblings, whose parent is the dragged
     * folder or something under it - the same cycle by a longer route.
     */
    function isSelfOrBelow(folderId, ancestorId) {
        const seen = new Set();

        for (let id = folderId; id; ) {
            if (id === ancestorId) return true;
            if (seen.has(id)) return true;
            seen.add(id);

            id =
                folders.value.find((folder) => folder.id === id)?.parentId ??
                null;
        }

        return false;
    }

    function onFolderDragOver(event, folder) {
        const types = dragTypes(event);

        // A document has no rank among folders: it can only go *into* one, so
        // the edges are not offered for it.
        if (types.includes(DOC_MIME)) {
            event.preventDefault();
            dropTarget.value = { id: folder.id, zone: "into" };

            return;
        }

        if (!types.includes(FOLDER_MIME)) return;
        if (
            null !== draggingFolderId.value &&
            isSelfOrBelow(folder.id, draggingFolderId.value)
        ) {
            return;
        }

        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
        dropTarget.value = { id: folder.id, zone: zoneAt(event) };
    }

    function onDragLeave(event) {
        // Only when the pointer left the row for good - entering a child fires
        // dragleave too, and clearing then makes the indicator flicker.
        if (!event.currentTarget.contains(event.relatedTarget)) {
            dropTarget.value = null;
        }
    }

    function onDragEnd() {
        draggingFolderId.value = null;
        dropTarget.value = null;
    }

    async function moveDocumentInto(documentId, folderId) {
        const data = await moveRequest(DOCUMENT_BULK_MOVE, {
            ids: [Number(documentId)],
            folderId,
        });
        if (!data?.success) return;

        toast.success(t("backend.ged.documents.moved"));
        onChanged?.();
    }

    /**
     * @param {object}  options
     * @param {boolean} options.quiet no toast, no notice - the caller is
     *                                mid-gesture and reports the outcome itself
     * @returns {Promise<boolean>} whether the server accepted it
     */
    async function reparentFolder(folderId, parentId, { quiet = false } = {}) {
        const data = await moveRequest(
            buildPath(FOLDER_MOVE, { id: folderId }),
            { parentId },
        );

        // The server refuses a folder filed inside its own descendant rather
        // than writing a tree no screen can show.
        if (!data?.success) {
            if (!quiet) toast.error(t("backend.ged.folders.errors.cycle"));

            return false;
        }

        adoptFolders(data.folders);
        if (!quiet) {
            toast.success(t("backend.ged.documents.moved"));
            onChanged?.();
        }

        return true;
    }

    /**
     * Reorder within one parent's children.
     *
     * The server takes the whole sibling order rather than a position, so the
     * new list is built here from the tree on screen. A folder dragged in from
     * another level has no place in that list yet - it is reparented instead,
     * and lands at the end.
     */
    /**
     * Drop on the top or bottom band: put the folder beside the target.
     *
     * "Beside" means two things when the folder comes from another branch, and
     * only one of them used to happen. `reorder` assigns positions to the ids
     * it is handed and never touches a parent, so a folder dropped next to a
     * row in another branch was renumbered into that branch's order while
     * staying exactly where it was - it appeared to jump somewhere nobody asked
     * for, and a nested folder could never be dropped beside a root folder to
     * get back out. The parent is changed first now, and only then the order.
     */
    async function reorderBeside(folderId, target, zone) {
        const parentId = target.parentId ?? null;
        const current = folders.value.find((folder) => folder.id === folderId);

        if ((current?.parentId ?? null) !== parentId) {
            const moved = await reparentFolder(folderId, parentId, {
                quiet: true,
            });
            if (!moved) return;
        }

        // Read the siblings after the move, in the order the tree shows them -
        // position order, which is what `reorder` is about to rewrite.
        const siblings = allFlatFolders.value
            .filter((node) => (node.parentId ?? null) === parentId)
            .map((node) => node.id)
            .filter((id) => id !== folderId);

        const targetIndex = siblings.indexOf(target.id);
        if (-1 === targetIndex) return;

        siblings.splice(
            "before" === zone ? targetIndex : targetIndex + 1,
            0,
            folderId,
        );

        const data = await moveRequest(FOLDER_REORDER, { ids: siblings });
        if (!data?.success) return;

        adoptFolders(data.folders);
        toast.success(t("backend.ged.documents.moved"));
        onChanged?.();
    }

    async function onFolderDrop(event, target) {
        const types = dragTypes(event);
        if (!types.includes(DOC_MIME) && !types.includes(FOLDER_MIME)) return;

        event.preventDefault();

        // Read the zone off the drop itself: a dragleave race can have cleared
        // the hover state a moment before the pointer was released.
        const zone = types.includes(DOC_MIME) ? "into" : zoneAt(event);
        const documentId = event.dataTransfer.getData(DOC_MIME);
        const folderId = Number(event.dataTransfer.getData(FOLDER_MIME));

        dropTarget.value = null;
        draggingFolderId.value = null;

        if (documentId) return moveDocumentInto(documentId, target?.id ?? null);
        if (!folderId || folderId === target?.id) return;

        // The root row takes a folder out of every parent; it has no siblings
        // to be ordered against.
        if (!target) {
            await reparentFolder(folderId, null);

            return;
        }

        if (isSelfOrBelow(target.id, folderId)) {
            toast.error(t("backend.ged.folders.errors.cycle"));

            return;
        }

        if ("into" === zone) {
            await reparentFolder(folderId, target.id);

            return;
        }

        await reorderBeside(folderId, target, zone);
    }

    return {
        folderModal,
        folderForm,
        deletingFolder,
        folderParentSelectOptions,
        openCreateFolder,
        openEditFolder,
        submitFolder,
        confirmDeleteFolder,
        dropTarget,
        draggingFolderId,
        onFolderDragStart,
        onFolderDragOver,
        onDragLeave,
        onDragEnd,
        onFolderDrop,
    };
}
