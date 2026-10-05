import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * The addresses that open this deck without an account.
 *
 * A link that was opened is never deleted, only revoked: "who could open this,
 * and until when" is a question worth being able to answer after the fact, and
 * a deleted row answers nothing. The list therefore shows the revoked ones too,
 * faded. One nobody ever opened has nothing to remember: it is deleted.
 */
export function useDeckSharing(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const sharing = ref(false);
    const links = ref([...(props.shareLinks ?? [])]);

    /**
     * The pictures a recipient will not see, recomputed on every write.
     *
     * The server answers with them alongside the links, so publishing a
     * picture and creating a link in the same sitting clears the warning
     * without a reload.
     */
    const withheld = ref([...(props.withheldPictures ?? [])]);
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
        if (Array.isArray(data?.withheldPictures))
            withheld.value = data.withheldPictures;
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

            if (!data?.success) {
                // 422: a duration or a password the rules refuse, said in words.
                const key = Object.values(data?.errors ?? {}).find(
                    (value) => "string" === typeof value && "" !== value,
                );
                if (key) toast.error(t(key));

                return;
            }

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

    const isDeletable = (link) => 0 === link.openCount;

    /**
     * A retired or expired link that was opened keeps its row (who could read)
     * and has nothing left to offer: it is hidden from the list rather than left
     * to clutter it. One nobody opened is deleted instead.
     */
    const isHideable = (link) =>
        !link.hidden && !isLive(link) && !isDeletable(link);

    /** The hidden links show only on request. */
    const showHidden = ref(false);
    const hiddenCount = computed(
        () => links.value.filter((link) => link.hidden).length,
    );
    const shownLinks = computed(() =>
        links.value.filter((link) => showHidden.value || !link.hidden),
    );

    async function hide(link) {
        const data = await request(
            buildPath(props.shareHidePath, { linkId: link.id }),
        );

        if (!data?.success) {
            // Still live (retired meanwhile elsewhere, or not yet): the server says so.
            const key = Object.values(data?.errors ?? {}).find(
                (value) => "string" === typeof value && "" !== value,
            );
            if (key) toast.error(t(key));

            return;
        }

        apply(data);
        toast.success(t("backend.studio.decks.share_hidden_toast"));
    }

    async function remove(link) {
        const data = await request(
            buildPath(props.shareDeletePath, { linkId: link.id }),
        );

        if (!data?.success) {
            // Opened in the meantime: the server says so, and it is now a revocation.
            const key = Object.values(data?.errors ?? {}).find(
                (value) => "string" === typeof value && "" !== value,
            );
            if (key) toast.error(t(key));

            return;
        }

        apply(data);
        toast.success(t("backend.studio.decks.share_deleted_toast"));
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
        withheld,
        creating,
        copiedId,
        createLink,
        revoke,
        remove,
        hide,
        copy,
        isLive,
        isDeletable,
        isHideable,
        showHidden,
        hiddenCount,
        shownLinks,
    };
}
