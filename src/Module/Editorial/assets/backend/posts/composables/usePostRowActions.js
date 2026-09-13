import { useI18n } from "vue-i18n";
import { Copy, Eye, Pencil, Trash2 } from "lucide-vue-next";

/**
 * What one publication row offers, given who is looking.
 *
 * This list shows live publications only: what is in the trash is restored or
 * destroyed from the Trash screen, which is the one place that does it for
 * every module. The `trashed` guards below stay as a floor, because the list
 * endpoint can still be asked for trashed rows.
 *
 * Returns a function rather than a computed: a list renders one of these per
 * row, and a computed per row would mean one watcher per row for values that
 * only change when the row does.
 */
export function usePostRowActions({
    can,
    editPath,
    confirmDelete,
    duplicate,
    preview,
    canPreview = false,
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

        // Après « Modifier », qui reste le geste courant sur cet écran, et
        // avant les deux qui écrivent. Aucun droit propre au-delà de voir la
        // liste : regarder une page telle que le visiteur la voit est ce
        // qu'un visiteur peut déjà faire, et un lecteur qui ne peut pas
        // modifier n'avait aucun moyen d'ouvrir une publication d'ici.
        if (canPreview && !post.trashed) {
            actions.push({
                key: "preview",
                color: "accent",
                icon: Eye,
                title: t("backend.posts.preview.open"),
                description: t("backend.posts.row_actions.preview_description"),
                onSelect: () => preview(post),
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
