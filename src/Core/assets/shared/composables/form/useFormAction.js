import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useServerErrors } from "@/shared/composables/form/useServerErrors.js";

/**
 * Unified composable for form submit actions.
 *
 * Orchestrates the three steps every form action needs:
 *   1. Client-side validation  (via useServerErrors → useForm)
 *   2. HTTP request            (via useRequest)
 *   3. Server response handling (errors → toast + field errors, success → callback)
 *
 * Replaces the repetitive useServerErrors + useRequest + manual if/else block.
 *
 * @param {Object}  options
 * @param {() => Record<string, () => string|null>} [options.rules]
 *   Lazy validation rules evaluated on each submit call.
 *   Keys are field names, values are validator functions that return an error
 *   string or null. Omit to skip client-side validation.
 * @param {() => string} options.url
 *   Lazy URL - called at submit time so dynamic paths work naturally.
 * @param {() => *} [options.body]
 *   Lazy request body - called at submit time.
 * @param {(data: *) => void|Promise<void>} [options.onSuccess]
 *   Called with the full server response object when data.success is true.
 *   Ideal for: toast.success(), closing a modal, refreshing a list.
 *
 * @returns {{ errors, loading, submit, validate, clearErrors }}
 *
 * Usage - replaces ~12 lines of boilerplate with 4:
 *
 *   const { errors, loading, submit } = useFormAction({
 *     rules: () => ({
 *       name: () => required(t("…"))(form.value.name),
 *     }),
 *     url: () => createPath,
 *     body: () => form.value,
 *     onSuccess: async () => {
 *       toast.success(t("…"));
 *       showModal.value = false;
 *       await reload();
 *     },
 *   });
 */
export function useFormAction({ rules, url, body, onSuccess } = {}) {
    const { errors, validate, clearErrors, handleErrors } = useServerErrors();
    const { loading, request } = useRequest();

    async function submit() {
        if (rules && !validate(rules())) return;
        const data = await request(url(), body?.());
        if (!data) return;
        if (data.success) {
            clearErrors();
            await onSuccess?.(data);
        } else {
            handleErrors(data.errors);
        }
    }

    return { errors, loading, submit, validate, clearErrors };
}
