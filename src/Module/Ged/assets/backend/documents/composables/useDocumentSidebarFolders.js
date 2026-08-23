import { ref, reactive, computed } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Sidebar-side folder CRUD modal. Talks to the existing `/backend/ged/folders`
 * endpoints (create / update / delete) - the dedicated /folders admin page
 * keeps using the same routes, so the two pages stay in sync.
 *
 * The create / update responses return the full refreshed folder list, which
 * we propagate to the `folders` ref so badge counts (computed elsewhere) stay
 * accurate.
 */
export function useDocumentSidebarFolders(
    props,
    folders,
    currentFolderId,
    flatFolders,
    allFlatFolders,
    navigateTo,
    reload,
) {
    const { t } = useI18n();
    const { request: submitRequest } = useRequest();
    const { request: deleteRequest } = useRequest();

    const folderModal = reactive({
        open: false,
        editing: null,
        errors: {},
        saving: false,
    });
    const folderForm = reactive({ name: "", parentId: null });

    function openCreateFolder() {
        folderModal.editing = null;
        folderModal.errors = {};
        folderForm.name = "";
        folderForm.parentId = currentFolderId.value;
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
                ? buildPath(props.folderEditPath, {
                      id: folderModal.editing.id,
                  })
                : props.folderCreatePath;
            const data = await submitRequest(url, { ...folderForm });
            if (!data) return;
            if (!data.success) {
                folderModal.errors = data.errors ?? {};
                return;
            }
            // The /folders endpoints return the refreshed full folder list
            // (already with the new tree shape) - counts come from the next
            // list response, but folder names/parents are live immediately.
            if (Array.isArray(data.folders)) {
                folders.value = data.folders.map((folder) => ({
                    ...folder,
                    documentCount:
                        folders.value.find(
                            (existing) => existing.id === folder.id,
                        )?.documentCount ?? 0,
                }));
            }
            toast.success(t("shared.common.saved"));
            folderModal.open = false;
            // Refresh document counts on the badges via a list reload.
            reload?.();
        } finally {
            folderModal.saving = false;
        }
    }

    const deletingFolder = ref(null);

    async function confirmDeleteFolder() {
        const folder = deletingFolder.value;
        if (!folder) return;
        try {
            const data = await deleteRequest(
                buildPath(props.folderDeletePath, { id: folder.id }),
            );
            if (!data) return;
            if (!data.success) {
                toast.error(t("shared.common.error"));
                return;
            }
            if (Array.isArray(data.folders)) folders.value = data.folders;
            else
                folders.value = folders.value.filter((f) => f.id !== folder.id);
            if (currentFolderId.value === folder.id) {
                navigateTo(folder.parentId ?? null);
                return;
            }
            reload?.();
            toast.success(t("shared.common.deleted"));
        } finally {
            deletingFolder.value = null;
        }
    }

    /** Excludes the folder being edited + its descendants from the parent picker. */
    const folderParentOptions = computed(() => {
        if (!folderModal.editing) return flatFolders.value;
        const forbidden = new Set([folderModal.editing.id]);
        const addDescendants = (id) => {
            for (const f of folders.value) {
                if (f.parentId === id && !forbidden.has(f.id)) {
                    forbidden.add(f.id);
                    addDescendants(f.id);
                }
            }
        };
        addDescendants(folderModal.editing.id);
        return allFlatFolders.value.filter((f) => !forbidden.has(f.id));
    });

    function withDepthLabel(list) {
        return list.map((f) => ({
            ...f,
            displayLabel: "\u00a0\u00a0".repeat(f.depth ?? 0) + f.name,
        }));
    }

    const folderEditOptions = computed(() =>
        withDepthLabel(allFlatFolders.value),
    );
    const folderParentSelectOptions = computed(() =>
        withDepthLabel(folderParentOptions.value),
    );

    return {
        folderModal,
        folderForm,
        openCreateFolder,
        openEditFolder,
        submitFolder,
        deletingFolder,
        confirmDeleteFolder,
        folderParentOptions,
        folderEditOptions,
        folderParentSelectOptions,
    };
}
