import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { required } from "@/shared/utils/validation/validators.js";

/**
 * The create / edit / delete of one piece of content, shared by both views.
 *
 * Extracted the moment the calendar needed it, rather than copied: the board
 * and the calendar edit the same rows, and two copies of a form drift the first
 * time a field is added to one of them. Everything a view does *not* share -
 * how it lays the cards out, what a drag means - stays in the view.
 *
 * @param {object} paths            itemCreatePath, itemUpdatePath, itemDeletePath
 * @param {(data: object) => void} applyBoard  hands the server's answer back
 * @param {(id: number) => void} removeLocally drops a deleted card from the view
 */
function emptyForm(columnId = "", scheduledAt = "") {
    // Ticked by default: the common case is that a dated card goes out, and
    // the opposite would mean ticking every new card.
    return {
        title: "",
        body: "",
        columnId,
        scheduledAt,
        reviewBy: "",
        showOnCalendar: true,
    };
}

function formFrom(item) {
    return {
        title: item.title ?? "",
        body: item.body ?? "",
        columnId: item.columnId ?? "",
        // The wall clock the server already expressed in the space's zone, so
        // the field never converts and can never convert it wrong.
        scheduledAt: item.scheduledAtLocal ?? "",
        // Same form and same reason as the publication above.
        reviewBy: item.reviewByLocal ?? "",
        showOnCalendar: false !== item.showOnCalendar,
    };
}

export function useSpaceContentItemForm(paths, applyBoard, removeLocally) {
    const { t } = useI18n();

    const showItemForm = ref(false);
    const editingItem = ref(null);
    const itemForm = ref(emptyForm());

    const {
        errors: itemErrors,
        loading: itemLoading,
        submit: submitItem,
        clearErrors: clearItemErrors,
    } = useFormAction({
        rules: () => ({
            title: () =>
                required(t("suite.studio.space_content.errors.title_required"))(
                    itemForm.value.title,
                ),
            columnId: () =>
                required(
                    t("suite.studio.space_content.errors.column_required"),
                )(itemForm.value.columnId),
        }),
        url: () =>
            editingItem.value
                ? buildPath(paths.itemUpdatePath, { id: editingItem.value.id })
                : paths.itemCreatePath,
        body: () => itemForm.value,
        onSuccess: (data) => {
            showItemForm.value = false;
            toast.success(
                t(
                    editingItem.value
                        ? "suite.studio.space_content.item_updated"
                        : "suite.studio.space_content.item_created",
                ),
            );
            applyBoard(data);
        },
    });

    /**
     * Opens an empty form, already filed where the reader asked for it.
     *
     * The board hands a step, the calendar hands a day, and both are a
     * courtesy: somebody who clicked a Thursday means Thursday, and making them
     * pick it again in the form is asking twice.
     */
    function openItemCreate({ columnId = "", scheduledAt = "" } = {}) {
        editingItem.value = null;
        itemForm.value = emptyForm(String(columnId ?? ""), scheduledAt ?? "");
        clearItemErrors();
        showItemForm.value = true;
    }

    function openItemEdit(item) {
        editingItem.value = item;
        itemForm.value = formFrom(item);
        clearItemErrors();
        showItemForm.value = true;
    }

    // Removed locally rather than from the answer: putting a card in the
    // trash leaves the others' positions alone, so dropping it from the list
    // is exactly what the server did. Nothing is offered for binning: the
    // card keeps its files in the trash, ready for a restore.
    const {
        pendingDelete: pendingItemDelete,
        loading: itemDeleteLoading,
        confirm: confirmItemDelete,
        submit: deleteItem,
    } = useDelete(
        paths.itemDeletePath,
        (id) => removeLocally(id),
        "suite.studio.space_content.item_deleted",
    );

    return {
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
    };
}
