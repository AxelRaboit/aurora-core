import { useI18n } from "vue-i18n";
import { Check, ShieldAlert, Trash2 } from "lucide-vue-next";

/**
 * What a comment offers, which depends on where it already sits.
 *
 * Approving an approved comment and spamming a spammed one are both no-ops, so
 * neither is offered - a rule about the record's state that was two conditions
 * in a template and is now readable in one place.
 *
 * Returns a function rather than a computed: a list renders one per row.
 */
export function useCommentRowActions({
    can,
    approve,
    markAsSpam,
    confirmDelete,
}) {
    const { t } = useI18n();

    return function actionsFor(comment) {
        const actions = [];

        if (
            can("editorial.comments.moderate") &&
            "approved" !== comment.status
        ) {
            actions.push({
                key: "approve",
                color: "emerald",
                icon: Check,
                title: t("backend.comments.approve"),
                description: t(
                    "backend.comments.row_actions.approve_description",
                ),
                onSelect: () => approve(comment),
            });
        }

        if (can("editorial.comments.moderate") && "spam" !== comment.status) {
            actions.push({
                key: "spam",
                color: "amber",
                icon: ShieldAlert,
                title: t("backend.comments.spam"),
                description: t("backend.comments.row_actions.spam_description"),
                onSelect: () => markAsSpam(comment),
            });
        }

        if (can("editorial.comments.delete")) {
            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t(
                    "backend.comments.row_actions.delete_description",
                ),
                onSelect: () => confirmDelete(comment),
            });
        }

        return actions;
    };
}
