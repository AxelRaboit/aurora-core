import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Les requêtes d'une liste de livrables, et ce qu'on dit quand le serveur
 * refuse.
 *
 * Une action de liste (changer de rayon, dupliquer, supprimer, ouvrir au
 * client) qui recevait `success: false` ne disait rien : le 404 d'un livrable
 * supprimé entre-temps par un collègue laissait sa ligne à l'écran avec le seul
 * message générique, et un 422 n'en laissait aucun. Ici, une réponse refusée
 * dit pourquoi, et quand la liste est périmée (le livrable n'existe plus, ou
 * n'est plus à nous), elle se recharge.
 *
 * `send` rend la réponse du serveur, refusée ou non, et rien quand la requête
 * elle-même a échoué (réseau, 5xx) : `useRequest` a déjà dit son message. Un
 * code que l'appelant traite lui-même (`own`) ne fait pas de message d'ici.
 *
 * @param {object}   options
 * @param {string}   [options.listPath] La route qui rend la liste à jour.
 * @param {Function} [options.onList]   Reçoit cette réponse, pour redessiner.
 */
export function useDeliverableRequest({ listPath = "", onList = null } = {}) {
    const { t } = useI18n();
    const { request } = useRequest();

    /** Le premier message d'erreur de champ, traduit. */
    function fieldError(data) {
        const key = Object.values(data?.errors ?? {}).find(
            (value) => "string" === typeof value && "" !== value,
        );

        return key ? t(key) : null;
    }

    async function refreshList() {
        if (!listPath || !onList) return;

        const data = await request(listPath, null, {
            method: HttpMethod.Get,
            silent: true,
        });
        if (data?.success) onList(data);
    }

    async function send(path, body = {}, { own = [] } = {}) {
        // 403 et 404 sont des réponses, pas des pannes : le serveur dit que ce
        // livrable n'existe plus ou n'est plus à nous, et la liste en tient compte.
        const data = await request(path, body, { accept: [403, 404] });

        if (null === data || data.success) return data;
        if (own.includes(data.error)) return data;

        toast.error(
            fieldError(data) ??
                ("not_found" === data.error
                    ? t("suite.studio.deliverables.gone")
                    : "forbidden" === data.error
                      ? t("suite.studio.deliverables.not_allowed")
                      : t("shared.common.error")),
        );

        if (["not_found", "forbidden"].includes(data.error))
            await refreshList();

        return data;
    }

    return { send, refreshList, fieldError };
}
