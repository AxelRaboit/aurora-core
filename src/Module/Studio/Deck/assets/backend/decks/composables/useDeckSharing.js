import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The addresses that open this deck without an account.
 *
 * A link is never deleted, only revoked: "who could open this, and until when"
 * is a question worth being able to answer after the fact, and a deleted row
 * answers nothing. The list therefore shows the revoked ones too, faded.
 */
export function useDeckSharing(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const sharing = ref(false);
    const links = ref([...(props.shareLinks ?? [])]);
    const newLabel = ref("");
    const expiresInDays = ref("");
    const newPassword = ref("");
    const creating = ref(false);
    const copiedId = ref(null);

    const isLive = (link) =>
        !link.revokedAt &&
        (!link.expiresAt || new Date(link.expiresAt) > new Date());

    function apply(data) {
        if (Array.isArray(data?.shareLinks)) links.value = data.shareLinks;
    }

    async function createLink() {
        if (creating.value) return;

        creating.value = true;

        try {
            const data = await request(props.shareCreatePath, {
                label: newLabel.value,
                expiresInDays: expiresInDays.value
                    ? Number(expiresInDays.value)
                    : null,
                password: newPassword.value,
            });

            if (!data?.success) return;

            apply(data);
            newLabel.value = "";
            // Cleared rather than kept: the field holds a secret, and a second
            // link created from this panel would otherwise silently inherit
            // the password of the first.
            newPassword.value = "";
            toast.success(t("backend.studio.decks.share_created"));
        } finally {
            creating.value = false;
        }
    }

    async function revoke(link) {
        const data = await request(
            buildPath(props.shareRevokePath, { linkId: link.id }),
        );

        if (!data?.success) return;

        apply(data);
        toast.success(t("backend.studio.decks.share_revoked_toast"));
    }

    /**
     * Copy, with a fallback that is not a failure.
     *
     * `navigator.clipboard` needs a secure context and a permission, and both
     * are routinely absent on a local instance over plain http. Showing the
     * address in place of the row's text then lets the reader select it by
     * hand, which is what they would have done anyway.
     */
    async function copy(link) {
        try {
            await navigator.clipboard.writeText(link.url);
            toast.success(t("backend.studio.decks.share_copied"));
        } catch {
            toast.message(t("backend.studio.decks.share_copy_manually"));
        }

        copiedId.value = link.id;
        setTimeout(() => {
            if (copiedId.value === link.id) copiedId.value = null;
        }, 2000);
    }

    return {
        sharing,
        links: computed(() => links.value),
        newLabel,
        expiresInDays,
        newPassword,
        creating,
        copiedId,
        createLink,
        revoke,
        copy,
        isLive,
    };
}
