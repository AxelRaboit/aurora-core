import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Ask the client to review what is waiting for their opinion.
 *
 * Behind a confirmation, because sending is not only an email: it issues a
 * fresh address for each recipient and revokes the previous one. Somebody who
 * clicks by mistake breaks the link their client had bookmarked, and that is
 * the kind of consequence a button must announce beforehand.
 *
 * The server returns what was waiting and how many people were notified.
 * Both matter: zero recipients for three waiting posts means no link of this
 * space can answer, which is a sharing problem and not a sending one. Saying
 * so is more useful than a "sent" that would leave people waiting for an
 * answer that will not come.
 */
export function useSpaceReviewInvite(reviewPath) {
    const { t } = useI18n();
    const { request } = useRequest();

    const confirming = ref(false);
    const sending = ref(false);

    async function send() {
        if (sending.value) return;
        sending.value = true;

        const data = await request(reviewPath, {});

        sending.value = false;
        if (!data?.success) return;

        confirming.value = false;

        if (0 === data.awaiting) {
            toast.info(t("suite.studio.space_content.review.nothing_awaiting"));

            return;
        }

        if (0 === data.notified) {
            toast.warning(
                t("suite.studio.space_content.review.nobody_to_write_to"),
            );

            return;
        }

        toast.success(
            t("suite.studio.space_content.review.sent", {
                count: data.notified,
            }),
        );
    }

    return { confirming, sending, send };
}
