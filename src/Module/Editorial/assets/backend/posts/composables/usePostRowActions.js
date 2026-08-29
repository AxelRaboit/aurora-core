import { useI18n } from "vue-i18n";
import { Copy, Flame, Pencil, Trash2, Undo2 } from "lucide-vue-next";

/**
 * What one publication row offers, given who is looking and where it sits.
 *
 * A post in the trash and a post on the shelf offer opposite things: one is
 * restored or burnt, the other is edited or thrown away. That is a rule about
 * the record, not a layout decision, so it lives here rather than as four
 * `v-if` in a table cell.
 *
 * Returns a function rather than a computed: a list renders one of these per
 * row, and a computed per row would mean one watcher per row for values that
 * only change when the row does.
 */
export function usePostRowActions({
    can,
    editPath,
    restore,
    confirmDelete,
    forceDelete,
    duplicate,
}) {
    const { t } = useI18n();

    return function actionsFor(post) {
        const actions = [];

        if (can("editorial.posts.edit") && !post.trashed) {
            actions.push({
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t("backend.posts.row_actions.edit_description"),
                href: editPath(post),
            });
        }

        // Needs the right to *create*, not to edit this one: duplicating makes a
        // new post, and starting from something you may only read is a reasonable
        // thing to want. Never on a trashed row - copying something on its way out
        // is nobody's intention.
        if (can("editorial.posts.create") && !post.trashed) {
            actions.push({
                key: "duplicate",
                color: "accent",
                icon: Copy,
                title: t("backend.posts.duplicate.action"),
                description: t(
                    "backend.posts.row_actions.duplicate_description",
                ),
                onSelect: () => duplicate(post),
            });
        }

        if (can("editorial.posts.delete") && post.trashed) {
            actions.push({
                key: "restore",
                color: "emerald",
                icon: Undo2,
                title: t("backend.posts.restore"),
                description: t("backend.posts.row_actions.restore_description"),
                onSelect: () => restore(post),
            });

            // Read last, as everywhere: the one that cannot be undone.
            actions.push({
                key: "force-delete",
                color: "rose",
                icon: Flame,
                title: t("backend.posts.force_delete"),
                description: t(
                    "backend.posts.row_actions.force_delete_description",
                ),
                onSelect: () => forceDelete(post),
            });
        }

        if (can("editorial.posts.delete") && !post.trashed) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t("backend.posts.row_actions.delete_description"),
                onSelect: () => confirmDelete(post),
            });
        }

        return actions;
    };
}
