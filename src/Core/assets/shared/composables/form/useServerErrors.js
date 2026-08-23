import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useForm } from "@/shared/composables/form/useForm.js";
import { translateServerErrors } from "@/shared/utils/validation/translateServerErrors.js";

/**
 * Extends useForm with server-side error handling.
 *
 * handleErrors(serverErrors)
 *   - translates i18n keys via translateServerErrors
 *   - toasts data.errors._global if present, falls back to generic message
 *   - pushes field errors into errors ref (readable via :error="errors.field")
 *
 * handleResponse(data, onSuccess?)
 *   - null data (network error already handled by useRequest) → no-op
 *   - data.success → clears errors, calls onSuccess(data)
 *   - data.errors → delegates to handleErrors
 *
 * Usage:
 *   const { errors, validate, handleResponse } = useServerErrors();
 *   const data = await request(url, payload);
 *   handleResponse(data, () => toast.success(t("...")));
 */
export function useServerErrors() {
    const { t } = useI18n();
    const { errors, validate, setErrors, clearErrors } = useForm();

    function handleErrors(serverErrors) {
        if (!serverErrors || Object.keys(serverErrors).length === 0) {
            toast.error(t("shared.common.error"));
            return;
        }
        const translated = translateServerErrors(t, serverErrors);
        if (translated._global) toast.error(translated._global);
        setErrors(translated);
    }

    function handleResponse(data, onSuccess) {
        if (!data) return; // null = network/HTTP error already handled by useRequest
        if (data.success) {
            clearErrors();
            onSuccess?.(data);
        } else {
            handleErrors(data.errors);
        }
    }

    return {
        errors,
        validate,
        setErrors,
        clearErrors,
        handleErrors,
        handleResponse,
    };
}
