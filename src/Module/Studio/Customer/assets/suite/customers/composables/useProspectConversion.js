import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

/**
 * Convert a prospect into a customer, from either of the two screens.
 *
 * **Only an address is asked for**, because it is the only field the status
 * requires: it is where the contract goes. The rest of the legal identity is
 * filled in on the sheet, the day it is known - and the full form is one
 * click away for that.
 *
 * The customers list and the spaces list both use it, and they do not have
 * the same thing to refresh afterwards: the first reads the list the server
 * sends back, the second has no reason to ask for it since it shows spaces.
 * Hence `applyResult`, which is their business.
 *
 * @param {string} convertPath          URL template carrying `__id__`
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
     * @param {object} record          the sheet or the space it starts from
     * @param {{id: number, name: string, email: string}} customer
     */
    function open(record, customer) {
        pending.value = { record, customer };
        // Prefilled when already known: a sheet that carries an address is
        // then converted without entering anything.
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
            toast.success(t("suite.studio.customers.converted"));
        } finally {
            loading.value = false;
        }
    }

    return { pending, email, error, loading, open, close, submit };
}
