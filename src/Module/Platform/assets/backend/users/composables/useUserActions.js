import { computed } from "vue";
import { useI18n } from "vue-i18n";
import {
    Eye,
    LayoutGrid,
    LogIn,
    Mail,
    Pencil,
    Power,
    ShieldCheck,
    Trash2,
} from "lucide-vue-next";
import { buildPath } from "@/shared/utils/http/buildPath.js";

/**
 * Which actions one operator may take on one user.
 *
 * This is not presentation: it is a set of rules about who may do what — an
 * invited account can be re-invited and no other, a developer's privileges are
 * not editable, a frontend account is impersonated through a different route
 * than a backend one. It lived in the component's `<script setup>` and made it
 * a hundred lines long, which is what the SFC convention exists to prevent.
 *
 * Pulling it out also made it testable. The conditions were previously provable
 * only by mounting the component and counting buttons; they can now be asserted
 * one by one, which matters because getting one wrong shows a destructive action
 * to somebody who should not have it.
 *
 * The order is the order they are meant to be read: look first, act in the
 * middle, destroy last.
 *
 * An action carries **either** `emitName` — the component emits it and the page
 * above decides what happens — **or** `href`, for the ones that are navigations
 * and should stay openable in a new tab.
 */
export function useUserActions(props) {
    const { t } = useI18n();

    return computed(() => {
        const user = props.user;
        const actions = [];

        actions.push({
            key: "view",
            color: "sky",
            icon: Eye,
            title: t("backend.users.view"),
            description: t("backend.users.row_actions.view_description"),
            emitName: "view",
        });

        if ("invited" === user.status && props.canAct) {
            actions.push({
                key: "resend",
                color: "amber",
                icon: Mail,
                title: t("backend.users.resend_invitation"),
                description: t("backend.users.row_actions.resend_description"),
                emitName: "resend",
            });
        }

        if (props.isDev && props.canAct && "backend" === user.type) {
            actions.push({
                key: "impersonate",
                color: "amber",
                icon: LogIn,
                title: t("backend.users.impersonate", { name: user.name }),
                description: t(
                    "backend.users.row_actions.impersonate_description",
                ),
                href: buildPath(props.impersonatePath, { email: user.email }),
            });
        }

        if (
            props.isDev &&
            "frontend" === user.type &&
            props.impersonateFrontPath
        ) {
            actions.push({
                key: "impersonate-front",
                color: "accent",
                icon: LogIn,
                title: t("backend.users.impersonate_front", {
                    name: user.name,
                }),
                description: t(
                    "backend.users.row_actions.impersonate_front_description",
                ),
                href: buildPath(props.impersonateFrontPath, { id: user.id }),
            });
        }

        if (props.canAct || props.canEdit) {
            actions.push({
                key: "edit",
                color: "accent",
                icon: Pencil,
                title: t("shared.common.edit"),
                description: t("backend.users.row_actions.edit_description"),
                emitName: "edit",
            });
        }

        if (props.isDev && props.canAct && props.hasPrivileges && !user.isDev) {
            actions.push({
                key: "privileges",
                color: "accent",
                icon: ShieldCheck,
                title: t("backend.users.privileges.title"),
                description: t(
                    "backend.users.row_actions.privileges_description",
                ),
                emitName: "privileges",
            });
        }

        if (props.canManageDisabledModules && props.canAct && !user.isDev) {
            actions.push({
                key: "modules",
                color: "accent",
                icon: LayoutGrid,
                title: t("backend.users.modules.title"),
                description: t("backend.users.row_actions.modules_description"),
                emitName: "modules",
            });
        }

        if (props.canAct) {
            const disabled = "disabled" === user.status;

            actions.push({
                key: "toggle-disabled",
                color: "amber",
                icon: Power,
                title: disabled
                    ? t("backend.users.enable")
                    : t("backend.users.disable"),
                description: disabled
                    ? t("backend.users.row_actions.enable_description")
                    : t("backend.users.row_actions.disable_description"),
                emitName: "toggle-disabled",
            });

            actions.push({
                key: "delete",
                color: "rose",
                icon: Trash2,
                title: t("shared.common.delete"),
                description: t("backend.users.row_actions.delete_description"),
                emitName: "delete",
            });
        }

        return actions;
    });
}
