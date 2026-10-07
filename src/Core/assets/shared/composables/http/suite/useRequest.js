import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { HttpStatus } from "@/shared/utils/http/HttpStatus.js";

/**
 * Generic HTTP composable.
 *
 * request(url, body?, methodOrOptions?)
 *
 * The third argument accepts either a method string (backward-compatible)
 * or an options object:
 *   { method, signal, noGuard, rawBody }
 *
 * Options:
 *   method    - HTTP method string (default: POST)
 *   signal    - AbortSignal for cancellation; aborted requests are silently ignored
 *   noGuard   - skip the loading guard so sequential calls in a loop work
 *   rawBody   - pass a non-JSON body (FormData, Blob…); Content-Type is omitted
 *   silent    - no toast on failure; the caller reports it, or deliberately
 *               does not. For a request the reader never asked for - a side
 *               panel filling itself on arrival - a red toast on every page of
 *               the module is louder than the thing it reports.
 *   accept    - further statuses whose JSON body goes back to the caller
 *               rather than into a generic toast. A 429 from a rate limiter
 *               says which limit and what to do, and a public page that
 *               showed « Une erreur est survenue » instead left a customer
 *               retrying into the same wall.
 */
export function useRequest() {
    const { t } = useI18n();
    const loading = ref(false);

    async function request(
        url,
        body = null,
        methodOrOptions = HttpMethod.Post,
    ) {
        const hasOptions =
            methodOrOptions !== null && typeof methodOrOptions === "object";
        const method = hasOptions
            ? (methodOrOptions.method ?? HttpMethod.Post)
            : methodOrOptions;
        const signal = hasOptions ? (methodOrOptions.signal ?? null) : null;
        const noGuard = hasOptions ? (methodOrOptions.noGuard ?? false) : false;
        const rawBody = hasOptions ? (methodOrOptions.rawBody ?? null) : null;
        const silent = hasOptions ? (methodOrOptions.silent ?? false) : false;
        const accept = hasOptions ? (methodOrOptions.accept ?? []) : [];

        if (!noGuard && loading.value) return null;
        if (!noGuard) loading.value = true;
        try {
            const fetchOptions = { method };
            if (signal) fetchOptions.signal = signal;

            if (rawBody !== null) {
                fetchOptions.headers = {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                };
                fetchOptions.body = rawBody;
            } else if (body !== null) {
                fetchOptions.headers = {
                    Accept: "application/json",
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                };
                fetchOptions.body = JSON.stringify(body);
            } else {
                fetchOptions.headers = {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                };
            }

            const response = await fetch(url, fetchOptions);
            if (
                !response.ok &&
                response.status !== HttpStatus.UnprocessableEntity &&
                response.status !== HttpStatus.Conflict &&
                response.status !== HttpStatus.BadRequest &&
                !accept.includes(response.status)
            )
                throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            if (error?.name === "AbortError") return null;
            if (!silent) toast.error(t("shared.common.error"));
            return null;
        } finally {
            if (!noGuard) loading.value = false;
        }
    }

    return { loading, request };
}
