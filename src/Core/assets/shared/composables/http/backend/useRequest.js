import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";
import { HttpStatus } from "@/shared/utils/http/HttpStatus.js";

/**
 * Generic HTTP composable.
 *
 * request(url, body?, methodOrOpts?)
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
 */
export function useRequest() {
    const { t } = useI18n();
    const loading = ref(false);

    async function request(url, body = null, methodOrOpts = HttpMethod.Post) {
        const isOpts =
            methodOrOpts !== null && typeof methodOrOpts === "object";
        const method = isOpts
            ? (methodOrOpts.method ?? HttpMethod.Post)
            : methodOrOpts;
        const signal = isOpts ? (methodOrOpts.signal ?? null) : null;
        const noGuard = isOpts ? (methodOrOpts.noGuard ?? false) : false;
        const rawBody = isOpts ? (methodOrOpts.rawBody ?? null) : null;

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
                response.status !== HttpStatus.BadRequest
            )
                throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (err) {
            if (err?.name === "AbortError") return null;
            toast.error(t("shared.common.error"));
            return null;
        } finally {
            if (!noGuard) loading.value = false;
        }
    }

    return { loading, request };
}
