import { reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

export function useUsersInvite(invitePath, roles, fetchUsers, options = {}) {
    const { t } = useI18n();
    const { request } = useRequest();
    const extraFields = options.extraFields ?? {};

    const inviteModal = reactive({ open: false, errors: {}, saving: false });
    const inviteForm = reactive({
        name: "",
        email: "",
        role: roles[0]?.value ?? "",
        message: "",
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, def]) => [key, def.default]),
        ),
    });

    function openInvite() {
        inviteModal.errors = {};
        inviteForm.name = "";
        inviteForm.email = "";
        inviteForm.role = roles[0]?.value ?? "";
        inviteForm.message = "";
        for (const [key, def] of Object.entries(extraFields)) {
            inviteForm[key] = def.default;
        }
        inviteModal.open = true;
    }

    async function submitInvite() {
        inviteModal.saving = true;
        inviteModal.errors = {};
        try {
            const data = await request(
                invitePath,
                { ...inviteForm },
                { noGuard: true },
            );

            // Null is transport or 5xx, and `request` has already toasted it -
            // the `catch` this replaces was showing a second message.
            if (data === null) return;

            if (!data.success) {
                inviteModal.errors = data.errors ?? {};
                return;
            }
            toast.success(t("backend.users.invitation_sent"));
            inviteModal.open = false;
            fetchUsers();
        } finally {
            inviteModal.saving = false;
        }
    }

    return { inviteModal, inviteForm, openInvite, submitInvite };
}
