import { useI18n } from "vue-i18n";
import {
    BadgeCheck,
    Building2,
    LayoutDashboard,
    Trash2,
} from "lucide-vue-next";
import { buildPath } from "@/shared/utils/http/buildPath.js";

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
 * **And a door to their spaces.** A customer exists for what is done with
 * them, and what is done with them lives in their spaces; from this list, no
 * path led there - you had to go through the menu, open the spaces list and
 * type the name again. The entry types it for you: it opens the list
 * filtered on the company.
 *
 * **And first their page.** The sheet is no longer edited in a dialog of the
 * list: "Ouvrir" leads to the customer's page, where it fits whole, with what
 * surrounds it. Offered to anyone who sees the list, since the page is read
 * with the same right.
 *
 * @param {object} deps
 * @param {string} deps.showPath The address of a customer's page, with `__id__`.
 * @param {string} [deps.spacesPath] The address of the spaces list.
 * @param {(permission: string) => boolean} deps.can
 * @param {(record: object) => void} deps.convertToClient
 * @param {(record: object) => void} deps.confirmDelete
 */
export function useCustomerRowActions({
    showPath,
    spacesPath = "",
    can,
    convertToClient,
    confirmDelete,
}) {
    const { t } = useI18n();

    return function actionsFor(record) {
        const editable = can("studio.customers.edit");
        const actions = [
            {
                key: "open",
                color: "accent",
                icon: Building2,
                title: t("suite.studio.customers.open"),
                description: t(
                    "suite.studio.customers.row_actions.open_description",
                ),
                // A link and not a gesture: it must be able to open in another
                // tab.
                href: buildPath(showPath, { id: record.id }),
            },
        ];

        if (spacesPath && can("studio.spaces.view")) {
            actions.push({
                key: "spaces",
                icon: LayoutDashboard,
                title: t("suite.studio.customers.spaces"),
                description: t("suite.studio.customers.spaces_description"),
                // A link and not a gesture: it is a navigation, it must be
                // able to open in another tab.
                // By id: the company name as a search also brought back the
                // companies whose name contains it.
                href: `${spacesPath}?customer=${encodeURIComponent(record.id)}`,
            });
        }

        if (editable && "prospect" === record.status) {
            actions.push({
                key: "convert",
                color: "emerald",
                icon: BadgeCheck,
                title: t("suite.studio.customers.convert"),
                description: t(
                    "suite.studio.customers.row_actions.convert_description",
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
                    "suite.studio.customers.row_actions.delete_description",
                ),
                onSelect: () => confirmDelete(record),
            });
        }

        return actions;
    };
}
