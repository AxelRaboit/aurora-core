import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

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
        // An original goes to the trash with its alternates, which the trash
        // gives back together: left behind, they would be copies of nothing.
        const res = await bulkDeleteRequest(props.bulkDeletePath, {
            ids: [...selectedIds.value],
            withAlternates: true,
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
    // Asked in the move dialog when the selection holds an original.
    const bulkMoveWithAlternates = ref(true);

    async function bulkMove() {
        if (!selectedIds.value.size) return;
        const res = await bulkMoveRequest(props.bulkMovePath, {
            ids: [...selectedIds.value],
            folderId: bulkMoveTargetId.value,
            withAlternates: bulkMoveWithAlternates.value,
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
        toast.success(t("suite.ged.documents.bulk_moved"));
    }

    const { request: bulkCategoryRequest } = useRequest();
    // Null files the selection under no category at all.
    const bulkCategoryTargetId = ref(null);
    const openBulkCategory = ref(false);
    // A family split across two categories is found in neither: the
    // alternates follow their original unless told otherwise.
    const bulkCategoryWithAlternates = ref(true);

    async function bulkCategorize() {
        if (!props.bulkCategoryPath || !selectedIds.value.size) return;
        const res = await bulkCategoryRequest(props.bulkCategoryPath, {
            ids: [...selectedIds.value],
            categoryId: bulkCategoryTargetId.value,
            withAlternates: bulkCategoryWithAlternates.value,
        });
        if (!res) return;
        if (!res.success) {
            toast.error(t("shared.common.error"));
            return;
        }
        clearSelection();
        bulkCategoryTargetId.value = null;
        openBulkCategory.value = false;
        await reload?.();
        toast.success(
            t("suite.ged.documents.bulk_categorized", {
                count: res.categorized ?? 0,
            }),
        );
    }

    const { request: bulkStorageRequest } = useRequest();
    const bulkRelocating = ref(false);

    /**
     * Moves a whole selection to one suite.
     *
     * Reports what actually happened rather than a flat success. A selection
     * is legitimately a mix: some documents already there, one held by a move
     * still running, one whose suite refused. Saying "done" over that would
     * be a lie, and the reader would find out later.
     */
    async function bulkRelocate(disk) {
        if (!props.bulkStoragePath || !selectedIds.value.size) return;

        bulkRelocating.value = true;
        try {
            const res = await bulkStorageRequest(props.bulkStoragePath, {
                ids: [...selectedIds.value],
                disk,
            });
            if (!res) return;
            if (!res.success) {
                toast.error(t("shared.common.error"));

                return;
            }

            const done = (res.moved ?? 0) + (res.queued ?? 0);
            const skipped = (res.alreadyThere ?? 0) + (res.busy ?? 0);
            const failed = res.failed ?? 0;

            if (failed > 0) {
                toast.error(
                    t("suite.ged.documents.relocation.bulk_partial", {
                        done,
                        failed,
                    }),
                );
            } else {
                toast.success(
                    t("suite.ged.documents.relocation.bulk_done", {
                        done,
                        skipped,
                    }),
                );
            }

            clearSelection();
            isSelecting.value = false;
            await reload?.();
        } finally {
            bulkRelocating.value = false;
        }
    }

    return {
        doBulkDelete,
        bulkMoveTargetId,
        bulkMoveWithAlternates,
        openBulkMove,
        bulkMove,
        bulkRelocate,
        bulkRelocating,
        bulkCategoryTargetId,
        openBulkCategory,
        bulkCategoryWithAlternates,
        bulkCategorize,
    };
}
