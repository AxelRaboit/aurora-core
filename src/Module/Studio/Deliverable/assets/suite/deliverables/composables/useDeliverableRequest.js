import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * The requests of a deliverable list, and what is said when the server
 * refuses.
 *
 * A list action (change shelf, duplicate, delete, open to the client) that
 * got `success: false` said nothing: the 404 of a deliverable deleted in the
 * meantime by a colleague left its row on screen with only the generic
 * message, and a 422 left none at all. Here, a refused response says why,
 * and when the list is stale (the deliverable no longer exists, or is no
 * longer ours), it reloads.
 *
 * `send` returns the server response, refused or not, and nothing when the
 * request itself failed (network, 5xx): `useRequest` has already shown its
 * message. A code the caller handles itself (`own`) gets no message from here.
 *
 * @param {object}   options
 * @param {string}   [options.listPath] The route that returns the list up to date.
 * @param {Function} [options.onList]   Receives that response, to redraw.
 */
export function useDeliverableRequest({ listPath = "", onList = null } = {}) {
    const { t } = useI18n();
    const { request } = useRequest();

    /** The first field error message, translated. */
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
        // 403 and 404 are answers, not failures: the server says this
        // deliverable no longer exists or is no longer ours, and the list adapts.
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
