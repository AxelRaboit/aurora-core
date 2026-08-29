import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Generic delete composable with confirmation flow.
 *
 * Both `deletePath` and `successMessageKey` accept either a static string or a
 * getter (() => string). Pass a getter when the value depends on reactive state
 * (e.g. a tab switching between soft-delete and force-delete endpoints).
 *
 * @param {string | (() => string)} deletePath - URL with __id__ placeholder
 * @param {(id: number) => void} onSuccess
 * @param {string | (() => string)} successMessageKey - i18n key for the success toast
 */
export function useDelete(deletePath, onSuccess, successMessageKey) {
    const { t } = useI18n();
    const pendingDelete = ref(null);
    const loading = ref(false);
    const { request } = useRequest();

    const resolve = (value) => (typeof value === "function" ? value() : value);

    function confirm(item) {
        pendingDelete.value = item;
    }

    async function submit() {
        if (loading.value || !pendingDelete.value) return;
        loading.value = true;
        try {
            const url = buildPath(resolve(deletePath), {
                id: pendingDelete.value.id,
            });
            // `noGuard` because this composable keeps its own `loading` and has
            // already returned above when one delete is in flight; the shared
            // guard would be a second one over the same state.
            const data = await request(url, null, { noGuard: true });

            // Null is transport or 5xx, and `request` has already toasted it.
            if (data === null) return;

            if (data.success) {
                const id = pendingDelete.value.id;
                pendingDelete.value = null;
                toast.success(t(resolve(successMessageKey)));
                onSuccess(id);
            }
        } finally {
            loading.value = false;
        }
    }

    return { pendingDelete, loading, confirm, submit };
}
