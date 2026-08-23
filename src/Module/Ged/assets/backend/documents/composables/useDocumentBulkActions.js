import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Bulk actions on the documents page (delete + move). Replaces the previous
 * inline `doBulkDelete` raw-fetch glue + `useDocumentBulkMove` - co-locates
 * the two flows so the toolbar wiring lives in one spot and both go through
 * `useRequest` (loading / error handling / CSRF).
 */
export function useDocumentBulkActions(
    props,
    items,
    selectedIds,
    isSelecting,
    clearSelection,
    currentFolderId,
    reload,
) {
    const { t } = useI18n();
    const { request: bulkDeleteRequest } = useRequest();
    const { request: bulkMoveRequest } = useRequest();

    async function doBulkDelete() {
        if (!props.bulkDeletePath || selectedIds.value.size === 0) return;
        const res = await bulkDeleteRequest(props.bulkDeletePath, {
            ids: [...selectedIds.value],
        });
        if (!res) return;
        if (!res.success) {
            toast.error(t("shared.common.error"));
            return;
        }
        items.value = items.value.filter(
            (doc) => !selectedIds.value.has(doc.id),
        );
        clearSelection();
        isSelecting.value = false;
        await reload?.();
    }

    const bulkMoveTargetId = ref(null);
    const openBulkMove = ref(false);

    async function bulkMove() {
        if (!selectedIds.value.size) return;
        const res = await bulkMoveRequest(props.bulkMovePath, {
            ids: [...selectedIds.value],
            folderId: bulkMoveTargetId.value,
        });
        if (!res) return;
        if (!res.success) {
            toast.error(t("shared.common.error"));
            return;
        }
        if (bulkMoveTargetId.value !== currentFolderId.value) {
            items.value = items.value.filter(
                (doc) => !selectedIds.value.has(doc.id),
            );
        }
        clearSelection();
        bulkMoveTargetId.value = null;
        openBulkMove.value = false;
        await reload?.();
        toast.success(t("backend.ged.documents.bulk_moved"));
    }

    return {
        doBulkDelete,
        bulkMoveTargetId,
        openBulkMove,
        bulkMove,
    };
}
