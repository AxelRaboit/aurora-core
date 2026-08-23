import { useI18n } from "vue-i18n";
import { Pencil, Trash2 } from "lucide-vue-next";

/**
 * The two actions most list rows have, and nothing else.
 *
 * Edit and delete, each behind its own permission. Half the lists in the
 * backend offer exactly this pair and no more - tags, categories, folders,
 * taxonomies, post types - and writing the same twenty lines in each of their
 * modules would be five copies of one rule, drifting apart the first time the
 * wording of a description changes.
 *
 * A list with anything else to offer writes its own composable instead of
 * bending this one. Two lists' worth of shared shape is a helper; five
 * different shapes forced through one signature is a knot.
 *
 * Returns a function rather than a computed, because a list renders one of
 * these per row - see `usePostRowActions` for the same reasoning at length.
 *
 * @param {object} deps
 * @param {(permission: string) => boolean} deps.can
 * @param {string} deps.editPermission
 * @param {string} deps.deletePermission
 * @param {(record: object) => void} deps.openEdit
 * @param {(record: object) => void} deps.confirmDelete
 * @param {string} deps.editDescription  translation key, what editing opens
 * @param {string} deps.deleteDescription translation key, what is lost
 */
export function useEditDeleteActions({
    can,
    editPermission,
    deletePermission,
    openEdit,
    confirmDelete,
    editDescription,
    deleteDescription,
}) {
    const { t } = useI18n();

    return function actionsFor(record) {
        const actions = [];

        if (can(editPermission)) {
            actions.push({
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t(editDescription),
                onSelect: () => openEdit(record),
            });
        }

        // Last, as everywhere: the one that takes something away is read after
        // the ones that do not.
        if (can(deletePermission)) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(deleteDescription),
                onSelect: () => confirmDelete(record),
            });
        }

        return actions;
    };
}
