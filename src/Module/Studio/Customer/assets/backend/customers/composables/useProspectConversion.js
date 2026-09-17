import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Convertir un prospect en client, depuis n'importe lequel des deux écrans.
 *
 * **Une seule adresse est demandée**, parce que c'est le seul champ que le
 * statut impose : c'est là que part le contrat. Le reste de l'identité légale
 * se remplit sur la fiche, le jour où on l'a - et le formulaire complet est à
 * un clic pour ça.
 *
 * La liste des clients et celle des espaces l'utilisent toutes les deux, et
 * elles n'ont pas la même chose à rafraîchir ensuite : la première relit la
 * liste que le serveur renvoie, la seconde n'a aucune raison de la demander
 * puisqu'elle affiche des espaces. D'où `applyResult`, qui est leur affaire.
 *
 * @param {string} convertPath          gabarit d'URL portant `__id__`
 * @param {(data: object, record: object) => void} applyResult
 */
export function useProspectConversion(convertPath, applyResult) {
    const { t } = useI18n();
    const { request } = useRequest();

    const pending = ref(null);
    const email = ref("");
    const error = ref("");
    const loading = ref(false);

    /**
     * @param {object} record          la fiche ou l'espace d'où l'on part
     * @param {{id: number, name: string, email: string}} customer
     */
    function open(record, customer) {
        pending.value = { record, customer };
        // Pré-rempli quand on l'a déjà : une fiche qui porte une adresse se
        // convertit alors sans rien saisir.
        email.value = customer.email ?? "";
        error.value = "";
    }

    function close() {
        pending.value = null;
    }

    async function submit() {
        if (!pending.value || loading.value) return;

        loading.value = true;
        error.value = "";

        try {
            const data = await request(
                buildPath(convertPath, { id: pending.value.customer.id }),
                { contractualEmail: email.value },
            );

            if (!data?.success) {
                error.value = data?.errors?.contractualEmail ?? "";

                return;
            }

            applyResult(data, pending.value.record);
            pending.value = null;
            toast.success(t("backend.studio.customers.converted"));
        } finally {
            loading.value = false;
        }
    }

    return { pending, email, error, loading, open, close, submit };
}
