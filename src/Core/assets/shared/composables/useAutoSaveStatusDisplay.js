import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Check, Loader2, AlertCircle } from "lucide-vue-next";

/**
 * Maps a `useAutoSave` status ref to a presentation object the template
 * can spread directly into an icon + label badge.
 *
 * Pairs with `src/Core/assets/shared/composables/useAutoSave.js` - keeps the
 * mapping out of every consumer SFC.
 *
 * Returned shape per state:
 *   { icon: LucideComponent, label: string, classes: string, spin: boolean }
 * or `null` when the status is `idle`.
 *
 * @param {import('vue').Ref<string>} statusRef
 */
export function useAutoSaveStatusDisplay(statusRef) {
    const { t } = useI18n();

    const display = computed(() => {
        switch (statusRef.value) {
            case "pending":
                return {
                    icon: Loader2,
                    label: t("shared.common.autosave.pending"),
                    classes: "text-muted",
                    spin: true,
                };
            case "saving":
                return {
                    icon: Loader2,
                    label: t("shared.common.autosave.saving"),
                    classes: "text-muted",
                    spin: true,
                };
            case "saved":
                return {
                    icon: Check,
                    label: t("shared.common.autosave.saved"),
                    classes: "text-emerald-400",
                    spin: false,
                };
            case "error":
                return {
                    icon: AlertCircle,
                    label: t("shared.common.autosave.error"),
                    classes: "text-rose-400",
                    spin: false,
                };
            default:
                return null;
        }
    });

    return { display };
}
