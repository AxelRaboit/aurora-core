import { useI18n } from "vue-i18n";
import { Eye, Pencil, Trash2 } from "lucide-vue-next";

/**
 * What the menu on a space row offers.
 *
 * Its own rather than `useEditDeleteActions`, on that composable's own
 * instruction: a list with anything else to offer writes its own instead of
 * bending it. The third entry is what forced it.
 *
 * **Opening the space is in the menu as well as on its name.** The name has
 * always been the way in, and it stays the way in; but a menu called Actions
 * that lists everything one can do to a row, minus the thing one actually does
 * to it fifty times a day, is a menu that answers the wrong question. A reader
 * who may not edit also had nothing in it at all.
 *
 * **It is a link, not a handler.** `href` is what lets somebody open a client's
 * space in another tab, which is exactly what one does when comparing two of
 * them - and it is what makes the entry behave like the row's own title.
 *
 * @param {object} deps
 * @param {(permission: string) => boolean} deps.can
 * @param {(record: object) => string} deps.boardHref where the space opens
 * @param {(record: object) => void} deps.openEdit
 * @param {(record: object) => void} deps.confirmDelete
 */
export function useSpaceRowActions({
    can,
    boardHref,
    openEdit,
    confirmDelete,
}) {
    const { t } = useI18n();

    return function actionsFor(record) {
        // First, because it is what the row is for.
        const actions = [
            {
                key: "view",
                color: "accent",
                icon: Eye,
                title: t("shared.common.view"),
                description: t(
                    "backend.studio.spaces.row_actions.view_description",
                ),
                href: boardHref(record),
            },
        ];

        if (can("studio.spaces.edit")) {
            actions.push({
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t(
                    "backend.studio.spaces.row_actions.edit_description",
                ),
                onSelect: () => openEdit(record),
            });
        }

        // Last, as everywhere: the one that takes something away is read after
        // the ones that do not.
        if (can("studio.spaces.delete")) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(
                    "backend.studio.spaces.row_actions.delete_description",
                ),
                onSelect: () => confirmDelete(record),
            });
        }

        return actions;
    };
}
