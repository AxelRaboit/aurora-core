import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Demander au client d'aller relire ce qui attend son avis.
 *
 * Derrière une confirmation, parce que l'envoi n'est pas seulement un courriel :
 * il émet une adresse neuve pour chaque destinataire et révoque la précédente.
 * Quelqu'un qui clique par erreur casse le lien que son client avait mis en
 * favori, et c'est le genre de conséquence qu'un bouton doit annoncer avant.
 *
 * Le serveur renvoie ce qui attendait et combien de personnes ont été
 * prévenues. Les deux comptent : zéro destinataire sur trois publications en
 * attente veut dire qu'aucun lien de cet espace ne peut répondre, ce qui est un
 * problème de partage et pas d'envoi. Le dire est plus utile qu'un « envoyé »
 * qui laisserait attendre une réponse qui ne viendra pas.
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
            toast.info(
                t("backend.studio.space_content.review.nothing_awaiting"),
            );

            return;
        }

        if (0 === data.notified) {
            toast.warning(
                t("backend.studio.space_content.review.nobody_to_write_to"),
            );

            return;
        }

        toast.success(
            t("backend.studio.space_content.review.sent", {
                count: data.notified,
            }),
        );
    }

    return { confirming, sending, send };
}
