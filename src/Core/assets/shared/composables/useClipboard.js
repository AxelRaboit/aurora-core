import { toast } from "vue-sonner";
import { useI18n } from "vue-i18n";

/**
 * Thin wrapper over `navigator.clipboard.writeText` with a success/failure
 * toast. Centralises the copy-to-clipboard pattern that list pages (Media,
 * GED, …) and tools (password generator) would otherwise each reimplement.
 *
 * `copy(text, successKey?)` - `successKey` is an i18n key for the toast
 * (defaults to a generic "copied" message).
 */
export function useClipboard() {
    const { t } = useI18n();

    async function copy(text, successKey = "shared.common.copied") {
        if (!text) return false;
        try {
            await navigator.clipboard.writeText(text);
            toast.success(t(successKey));
            return true;
        } catch {
            toast.error(t("shared.common.error"));
            return false;
        }
    }

    return { copy };
}
