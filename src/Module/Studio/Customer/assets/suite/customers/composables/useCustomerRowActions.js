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
 * **Et une porte vers ses espaces.** Un client existe pour ce qu'on fait avec
 * lui, et ce qu'on fait avec lui vit dans ses espaces ; depuis cette liste,
 * aucun chemin n'y menait - il fallait passer par le menu, ouvrir la liste des
 * espaces et retaper le nom. L'entrée le tape pour vous : elle ouvre la liste
 * filtrée sur la société.
 *
 * **Et d'abord sa page.** La fiche ne se modifie plus dans une fenêtre de la
 * liste : « Ouvrir » mène à la page du client, où elle tient entière, avec ce
 * qui l'entoure. Offert à quiconque voit la liste, puisque la page se lit avec
 * le même droit.
 *
 * @param {object} deps
 * @param {string} deps.showPath L'adresse de la page d'un client, avec `__id__`.
 * @param {string} [deps.spacesPath] L'adresse de la liste des espaces.
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
                // Un lien et non un geste : il doit pouvoir s'ouvrir dans un
                // autre onglet.
                href: buildPath(showPath, { id: record.id }),
            },
        ];

        if (spacesPath && can("studio.spaces.view")) {
            actions.push({
                key: "spaces",
                icon: LayoutDashboard,
                title: t("suite.studio.customers.spaces"),
                description: t("suite.studio.customers.spaces_description"),
                // Un lien et non un geste : c'est une navigation, elle doit
                // pouvoir s'ouvrir dans un autre onglet.
                // Par identifiant : la raison sociale en recherche ramenait
                // aussi les sociétés dont le nom la contient.
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
