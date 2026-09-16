import { useI18n } from "vue-i18n";
import { Eye, Pencil, Trash2 } from "lucide-vue-next";

/**
 * What the menu on a card offers, which depends on what the reader may do.
 *
 * Its own rather than `useEditDeleteActions`, on that composable's own
 * instruction: a list with anything else to offer writes its own instead of
 * bending it. The third entry is what forced it, and it was not cosmetic.
 *
 * **A reader who may not edit had no way into a card at all.** The menu held
 * edit and delete, both behind `studio.spaces.edit`, so for them it was empty;
 * and a board card is not a link. They saw a title and a date and could never
 * read the body, the thread, or the files - on a screen whose whole purpose is
 * to be read by the people a client engagement involves.
 *
 * So the menu offers `voir` exactly when it cannot offer `modifier`. Not both:
 * two entries opening the same panel, one of which happens to disable its
 * fields, is a menu that makes the reader guess which one they want.
 *
 * @param {object} deps
 * @param {(permission: string) => boolean} deps.can
 * @param {(record: object) => void} deps.open      opens the card's panel
 * @param {(record: object) => void} deps.confirmDelete
 */
export function useSpaceCardActions({ can, open, confirmDelete }) {
    const { t } = useI18n();

    return function actionsFor(record) {
        const editable = can("studio.spaces.edit");

        const actions = [
            {
                key: editable ? "edit" : "view",
                color: "accent",
                icon: editable ? Pencil : Eye,
                title: t(
                    editable ? "shared.common.edit" : "shared.common.view",
                ),
                description: t(
                    editable
                        ? "backend.studio.space_content.row_actions.edit_description"
                        : "backend.studio.space_content.row_actions.view_description",
                ),
                onSelect: () => open(record),
            },
        ];

        // Last, as everywhere: the one that takes something away is read
        // after the ones that do not.
        if (editable) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(
                    "backend.studio.space_content.row_actions.delete_description",
                ),
                onSelect: () => confirmDelete(record),
            });
        }

        return actions;
    };
}
