import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { UserStatus } from "@core/utils/enums/user/userStatus.js";

export function useUsersActions(props, fetchUsers) {
    const { t } = useI18n();
    const { request } = useRequest();

    // ── View ─────────────────────────────────────────────────────────────────
    const viewingUser = ref(null);

    async function openView(user) {
        viewingUser.value = { ...user, subordinates: [], subordinatesCount: 0 };
        const data = await request(
            buildPath(props.showPath, { id: user.id }),
            null,
            { method: HttpMethod.Get, noGuard: true },
        );
        if (data?.success && viewingUser.value?.id === user.id)
            viewingUser.value = data.user;
    }

    // ── Resend invitation ─────────────────────────────────────────────────────
    async function resendInvitation(user) {
        const data = await request(
            buildPath(props.resendInvitationPath, { id: user.id }),
        );
        if (!data) return;
        if (data.success) {
            toast.success(t("suite.users.invitation_resent"));
            fetchUsers();
        } else toast.error(t("shared.common.error"));
    }

    // ── Toggle disabled ───────────────────────────────────────────────────────
    const togglingUser = ref(null);

    function askToggleDisabled(user) {
        togglingUser.value = user;
    }

    async function confirmToggleDisabled() {
        const user = togglingUser.value;
        if (!user) return;
        const data = await request(
            buildPath(props.toggleDisabledPath, { id: user.id }),
        );
        togglingUser.value = null;
        if (!data) return;
        if (data.success) {
            toast.success(t("shared.common.saved"));
            fetchUsers();
        } else toast.error(t("shared.common.error"));
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    const deletingUser = ref(null);

    async function confirmDelete() {
        const user = deletingUser.value;
        if (!user) return;
        const data = await request(
            buildPath(props.deletePath, { id: user.id }),
        );
        deletingUser.value = null;
        if (!data) return;
        if (data.success) {
            toast.success(t("shared.common.deleted"));
            fetchUsers();
        } else toast.error(t("shared.common.error"));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function statusBadgeColor(status) {
        if ("active" === status) return "emerald";
        if ("invited" === status) return "amber";
        return "rose";
    }

    // By id only: the same address can hold a suite account and a public
    // site account, and the second is not the one signed in.
    const isCurrent = (user) => user.id === props.currentUserId;
    const canActOn = (user) =>
        !isCurrent(user) && props.currentUserPriority >= user.rolePriority;
    const canEditUser = (user) => isCurrent(user) || canActOn(user);

    return {
        viewingUser,
        openView,
        resendInvitation,
        togglingUser,
        askToggleDisabled,
        confirmToggleDisabled,
        deletingUser,
        confirmDelete,
        statusBadgeColor,
        isCurrent,
        canActOn,
        canEditUser,
        UserStatus,
    };
}
