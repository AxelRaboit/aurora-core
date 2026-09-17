import { useI18n } from "vue-i18n";
import { BadgeCheck, Pencil, Trash2 } from "lucide-vue-next";

/**
 * What the menu on a customer row offers.
 *
 * Its own rather than `useEditDeleteActions`, on that composable's own
 * instruction: a list with anything else to offer writes its own instead of
 * bending it.
 *
 * **The third entry only exists while it has something to say.** Converting is
 * offered on a prospect and on nothing else - a client is already converted,
 * and an entry that would be greyed out on two rows in three is noise in a menu
 * that has to be read quickly.
 *
 * @param {object} deps
 * @param {(permission: string) => boolean} deps.can
 * @param {(record: object) => void} deps.openEdit
 * @param {(record: object) => void} deps.convertToClient
 * @param {(record: object) => void} deps.confirmDelete
 */
export function useCustomerRowActions({
    can,
    openEdit,
    convertToClient,
    confirmDelete,
}) {
    const { t } = useI18n();

    return function actionsFor(record) {
        const actions = [];
        const editable = can("studio.customers.edit");

        if (editable) {
            actions.push({
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t(
                    "backend.studio.customers.row_actions.edit_description",
                ),
                onSelect: () => openEdit(record),
            });
        }

        if (editable && "prospect" === record.status) {
            actions.push({
                key: "convert",
                color: "emerald",
                icon: BadgeCheck,
                title: t("backend.studio.customers.convert"),
                description: t(
                    "backend.studio.customers.row_actions.convert_description",
                ),
                onSelect: () => convertToClient(record),
            });
        }

        // Last, as everywhere: the one that takes something away is read after
        // the ones that do not.
        if (can("studio.customers.delete")) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(
                    "backend.studio.customers.row_actions.delete_description",
                ),
                onSelect: () => confirmDelete(record),
            });
        }

        return actions;
    };
}
