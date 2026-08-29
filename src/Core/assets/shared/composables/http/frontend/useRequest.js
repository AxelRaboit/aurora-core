import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Lightweight HTTP composable for public-facing frontend pages.
 *
 * Differs from useRequest (admin) in two key ways:
 *   1. Never shows automatic toasts - errors are returned to the caller
 *      for inline display (field errors, banners, etc.)
 *   2. No loading guard - concurrent requests are allowed
 *
 * Returns the parsed JSON response, or null on network/unexpected error.
 * The caller is responsible for deciding how to surface errors to the user.
 *
 * @returns {{ loading: Ref<boolean>, request: (url, body?, method?) => Promise<*|null> }}
 *
 * Usage:
 *   const { loading, request } = useRequest();
 *   const data = await request(url, payload);
 *   if (!data) { errorMessage.value = t("shared.form.error"); return; }
 *   if (!data.success) { handleErrors(data.errors); return; }
 */
export function useRequest() {
    const { t } = useI18n();
    const loading = ref(false);

    async function request(url, body = null, method = HttpMethod.Post) {
        loading.value = true;
        try {
            const options = {
                method,
                headers: {
                    Accept: "application/json",
                    // Symfony reads this through `isXmlHttpRequest()` to answer
                    // JSON rather than an HTML page. The admin wrapper has always
                    // sent it and `convention_no_raw_fetch` says this one does
                    // too; it did not, so every public-page call - comments,
                    // forms, the front login - was going out without it.
                    "X-Requested-With": "XMLHttpRequest",
                },
            };
            if (body !== null) {
                options.headers["Content-Type"] = "application/json";
                options.body = JSON.stringify(body);
            }
            const response = await fetch(url, options);
            return await response.json();
        } catch {
            return {
                success: false,
                errors: { _global: t("shared.form.error") },
            };
        } finally {
            loading.value = false;
        }
    }

    return { loading, request };
}
