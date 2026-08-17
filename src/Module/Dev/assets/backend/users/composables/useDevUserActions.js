import { useI18n } from "vue-i18n";
import { LogIn, Pencil, Shield, Trash2, UserRound } from "lucide-vue-next";
import { buildPath } from "@/shared/utils/http/buildPath.js";

/**
 * What a developer row offers — and what it never offers on your own account.
 *
 * Impersonating yourself, revoking your own developer role and deleting your
 * own account are each a way to lock the last developer out of the backend.
 * Three conditions that were three `v-if` in a template, and are one readable
 * rule here.
 */
export function useDevUserActions({
    impersonatePath,
    onEdit,
    onToggleRole,
    onDelete,
}) {
    const { t } = useI18n();

    return function actionsFor(user) {
        const actions = [
            {
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("backend.users.edit"),
                description: t("backend.users.row_actions.edit_description"),
                onSelect: () => onEdit(user),
            },
        ];

        if (user.isCurrent) {
            return actions;
        }

        actions.push({
            key: "impersonate",
            color: "amber",
            icon: LogIn,
            title: t("backend.users.impersonate", { name: user.name }),
            description: t("backend.users.row_actions.impersonate_description"),
            href: buildPath(impersonatePath, { email: user.email }),
        });

        actions.push({
            key: "toggle-role",
            color: user.isDevRole ? "accent" : "rose",
            icon: user.isDevRole ? UserRound : Shield,
            title: user.isDevRole
                ? t("backend.users.revoke_dev")
                : t("backend.users.grant_dev"),
            description: user.isDevRole
                ? t("backend.users.row_actions.revoke_dev_description")
                : t("backend.users.row_actions.grant_dev_description"),
            onSelect: () => onToggleRole(user),
        });

        actions.push({
            key: "delete",
            color: "rose",
            icon: Trash2,
            title: t("shared.common.delete"),
            description: t("backend.users.row_actions.dev_delete_description"),
            onSelect: () => onDelete(user),
        });

        return actions;
    };
}
