import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Generic helpers for the {field, value} inline-edit + simple-action JSON
 * endpoints used across admin pages. Centralises:
 *   - the POST + error toast plumbing
 *   - extracting the right translation key from `data.errors[field] / data.error`
 *   - emitting the canonical "Saved" toast on success
 *
 * Use it for inline-editable fields, validate/finalize/approve buttons, and
 * any one-shot mutation where the UI just needs to react to the returned
 * payload. For paginated CRUD lists, prefer `useDelete` / `useListPage`.
 *
 * Returns:
 *   - request               - the underlying `useRequest()` if the caller
 *                             needs the raw data (rare).
 *   - submit(url, body, opts) - POST JSON, toast on error, return parsed data
 *                               or null. Default success toast: shared.common.saved.
 *   - saveField(url, field, value, opts) - shorthand for inline `{field,value}` edits.
 *
 * `opts.successMessage` overrides the default toast key (set to `null` to
 * suppress). `opts.silent` skips error toasts (caller handles errors).
 */
export function useInlineEdit() {
    const { t } = useI18n();
    const { request } = useRequest();

    function resolveErrorKey(data, field = null) {
        if (!data) return "shared.common.error";
        if (field && data.errors?.[field]) return data.errors[field];
        if (data.errors) {
            const first = Object.values(data.errors)[0];
            if (first) return first;
        }
        return data.error ?? "shared.common.error";
    }

    /**
     * @param {string} url
     * @param {object|null} [body=null]
     * @param {{ field?: string|null, successMessage?: string|null, silent?: boolean }} [opts]
     * @returns {Promise<object|null>} parsed payload on success, null otherwise
     */
    async function submit(url, body = null, opts = {}) {
        const {
            field = null,
            successMessage = "shared.common.saved",
            silent = false,
        } = opts;
        const data = await request(url, body);
        if (!data?.success) {
            if (!silent) toast.error(t(resolveErrorKey(data, field)));
            return null;
        }
        if (successMessage) toast.success(t(successMessage));
        return data;
    }

    /**
     * Inline `{field, value}` PATCH-ish save. The success toast is on by default
     * (`shared.common.saved`); pass `successMessage: null` to silence it.
     *
     * @param {string} url
     * @param {string} field
     * @param {unknown} value
     * @param {{ successMessage?: string|null, silent?: boolean }} [opts]
     */
    function saveField(url, field, value, opts = {}) {
        return submit(url, { field, value }, { ...opts, field });
    }

    return { request, submit, saveField };
}
