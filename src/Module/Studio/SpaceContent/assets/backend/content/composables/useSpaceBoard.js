import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { useSpaceContentItemForm } from "./useSpaceContentItemForm.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { required } from "@/shared/utils/validation/validators.js";

/**
 * The board, and every write it can make.
 *
 * **The server always answers with the whole board**, and this replaces its
 * copy with what comes back rather than patching the row it changed. A drag
 * renumbers a column, deleting a step renumbers the rest, and a page that
 * reconciled those itself would drift from the server within three gestures.
 * The board is small; being right is cheaper than being clever.
 */
export function useSpaceBoard(initialColumns, initialItems, paths) {
    const { t } = useI18n();
    const { request } = useRequest();

    const columns = ref(initialColumns ?? []);
    const items = ref(initialItems ?? []);

    function applyBoard(data) {
        if (Array.isArray(data?.columns)) columns.value = data.columns;
        if (Array.isArray(data?.items)) items.value = data.items;
    }

    /** The cards of each column, in their stored order. */
    const grouped = computed(() =>
        columns.value.map((column) => ({
            column,
            cards: items.value
                .filter((item) => item.columnId === column.id)
                .sort((a, b) => a.position - b.position),
        })),
    );

    const isEmpty = computed(() => items.value.length === 0);

    const columnOptions = computed(() =>
        columns.value.map((column) => ({
            value: String(column.id),
            label: column.name,
        })),
    );

    const {
        showItemForm,
        editingItem,
        itemForm,
        itemErrors,
        itemLoading,
        openItemCreate,
        openItemEdit,
        submitItem,
        pendingItemDelete,
        itemDeleteLoading,
        confirmItemDelete,
        deleteItem,
    } = useSpaceContentItemForm(paths, applyBoard, (id) => {
        items.value = items.value.filter((item) => item.id !== id);
    });

    /**
     * Writes the order of one column after a drag.
     *
     * Called with the column the cards landed in and the ids in their new
     * order, which is everything the server needs: it sets each card's column
     * and its place in one pass, so a card that crossed columns and the cards
     * that shifted under it are all settled by the same call.
     */
    async function reorderItems(columnId, cards) {
        // Moved optimistically so the card does not snap back while the request
        // is in flight; the server's answer replaces this a moment later.
        cards.forEach((card, index) => {
            const local = items.value.find((item) => item.id === card.id);
            if (local) {
                local.columnId = columnId;
                local.position = index;
            }
        });

        const data = await request(paths.itemReorderPath, {
            columnId,
            itemIds: cards.map((card) => card.id),
        });
        applyBoard(data);
    }

    // --- steps ------------------------------------------------------------

    const showColumnForm = ref(false);
    const editingColumn = ref(null);
    const columnForm = ref({ name: "" });

    const {
        errors: columnErrors,
        loading: columnLoading,
        submit: submitColumn,
        clearErrors: clearColumnErrors,
    } = useFormAction({
        rules: () => ({
            name: () =>
                required(
                    t(
                        "backend.studio.space_content.errors.column_name_required",
                    ),
                )(columnForm.value.name),
        }),
        url: () =>
            editingColumn.value
                ? buildPath(paths.columnUpdatePath, {
                      id: editingColumn.value.id,
                  })
                : paths.columnCreatePath,
        body: () => columnForm.value,
        onSuccess: (data) => {
            showColumnForm.value = false;
            toast.success(
                t(
                    editingColumn.value
                        ? "backend.studio.space_content.column_updated"
                        : "backend.studio.space_content.column_created",
                ),
            );
            applyBoard(data);
        },
    });

    function openColumnCreate() {
        editingColumn.value = null;
        columnForm.value = { name: "" };
        clearColumnErrors();
        showColumnForm.value = true;
    }

    function openColumnEdit(column) {
        editingColumn.value = column;
        columnForm.value = { name: column.name };
        clearColumnErrors();
        showColumnForm.value = true;
    }

    // A step holding cards, or the last one, is refused by the server with a
    // sentence; `useDelete` shows it as it came. Nothing here has to know the
    // two rules, which is why they are stated once, in the Manager.
    const {
        pendingDelete: pendingColumnDelete,
        loading: columnDeleteLoading,
        confirm: confirmColumnDelete,
        submit: deleteColumn,
    } = useDelete(
        paths.columnDeletePath,
        (id) => {
            columns.value = columns.value.filter((column) => column.id !== id);
        },
        "backend.studio.space_content.column_deleted",
    );

    return {
        columns,
        items,
        grouped,
        isEmpty,
        columnOptions,
        showItemForm,
        editingItem,
        itemForm,
        itemErrors,
        itemLoading,
        openItemCreate,
        openItemEdit,
        submitItem,
        pendingItemDelete,
        itemDeleteLoading,
        confirmItemDelete,
        deleteItem,
        reorderItems,
        showColumnForm,
        editingColumn,
        columnForm,
        columnErrors,
        columnLoading,
        openColumnCreate,
        openColumnEdit,
        submitColumn,
        pendingColumnDelete,
        columnDeleteLoading,
        confirmColumnDelete,
        deleteColumn,
    };
}
