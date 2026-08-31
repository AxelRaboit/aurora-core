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

    const dragOverFolderId = ref(null);

    function acceptsDrag(event) {
        const types = event.dataTransfer?.types ?? [];

        return types.includes(DOC_MIME) || types.includes(FOLDER_MIME);
    }

    function onFolderDragOver(event, folderId) {
        if (!acceptsDrag(event)) return;
        event.preventDefault();
        dragOverFolderId.value = folderId;
    }

    function onDragLeave() {
        dragOverFolderId.value = null;
    }

    async function onFolderDrop(event, targetFolderId) {
        if (!acceptsDrag(event)) return;
        event.preventDefault();
        dragOverFolderId.value = null;

        const documentId = event.dataTransfer.getData(DOC_MIME);
        const folderId = event.dataTransfer.getData(FOLDER_MIME);

        if (documentId) {
            const data = await moveRequest(DOCUMENT_BULK_MOVE, {
                ids: [Number(documentId)],
                folderId: targetFolderId,
            });
            if (!data?.success) return;

            toast.success(t("backend.ged.documents.moved"));
            onChanged?.();

            return;
        }

        if (!folderId || Number(folderId) === targetFolderId) return;

        const data = await moveRequest(
            buildPath(FOLDER_MOVE, { id: Number(folderId) }),
            { parentId: targetFolderId },
        );
        if (!data?.success) return;

        adoptFolders(data.folders);
        toast.success(t("backend.ged.documents.moved"));
        onChanged?.();
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
        dragOverFolderId,
        onFolderDragOver,
        onDragLeave,
        onFolderDrop,
    };
}
