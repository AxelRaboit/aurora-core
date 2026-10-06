import { reactive } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

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
        // Create the account without contacting anyone. The server then issues
        // no token and sends no email; the invitation goes out when the
        // account is activated from the list.
        disabled: false,
        // The administration or the public site. A frontend account gets
        // ROLE_USER whatever role is sent: the server forces it, the write
        // boundary being the only place that counts.
        type: "suite",
        ...Object.fromEntries(
            Object.entries(extraFields).map(([key, definition]) => [
                key,
                definition.default,
            ]),
        ),
    });

    function openInvite() {
        inviteModal.errors = {};
        inviteForm.name = "";
        inviteForm.email = "";
        inviteForm.role = roles[0]?.value ?? "";
        inviteForm.message = "";
        inviteForm.disabled = false;
        inviteForm.type = "suite";
        for (const [key, definition] of Object.entries(extraFields)) {
            inviteForm[key] = definition.default;
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
            // Nothing was sent when the account is created disabled: announcing
            // a sent invitation would make someone wait for an email that does
            // not exist.
            toast.success(
                inviteForm.disabled
                    ? t("suite.users.account_created_disabled")
                    : t("suite.users.invitation_sent"),
            );
            inviteModal.open = false;
            fetchUsers();
        } finally {
            inviteModal.saving = false;
        }
    }

    return { inviteModal, inviteForm, openInvite, submitInvite };
}
