/**
 * Server-side validation responses follow the project convention of returning
 * translation keys (e.g. "photo.galleries.errors.slug_taken") rather than
 * pre-translated strings - see Aurora\Core\Validation\Service\PayloadValidator.
 *
 * UI components (AppInput, AppTextarea…) display the `error` prop literally,
 * so admin screens must run the server payload through vue-i18n before
 * handing it to setErrors().
 *
 * Usage:
 *   import { useI18n } from "vue-i18n";
 *   import { translateServerErrors } from "@/shared/utils/validation/translateServerErrors.js";
 *   const { t } = useI18n();
 *   ...
 *   setErrors(translateServerErrors(t, data?.errors));
 *
 * Values that don't look like a translation key (no dot) pass through
 * unchanged, so already-translated messages or domain-specific tokens are
 * left intact.
 *
 * @param {(key: string, params?: object) => string} t - vue-i18n translate fn
 * @param {Record<string, string> | null | undefined} errors - raw server payload
 * @returns {Record<string, string>}
 */
export function translateServerErrors(t, errors) {
    const out = {};
    for (const [field, value] of Object.entries(errors ?? {})) {
        out[field] =
            typeof value === "string" && value.includes(".") ? t(value) : value;
    }
    return out;
}
