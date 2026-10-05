import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

export function useProfileMood(moodPath, initialMessage, maxLength) {
    const { t } = useI18n();

    const moodMessage = ref(initialMessage ?? "");
    const moodError = ref("");
    const { loading: moodLoading, request } = useRequest();

    async function saveMood() {
        const trimmed = moodMessage.value.trim();
        if (trimmed.length > maxLength) {
            moodError.value = t("suite.profile.mood.errors.too_long");
            return;
        }

        moodError.value = "";
        const data = await request(moodPath, { moodMessage: trimmed });
        if (!data) return;
        if (data.success) {
            moodMessage.value = data.moodMessage ?? "";
            toast.success(t("suite.profile.mood.saved"));
        } else {
            moodError.value =
                data.errors?.moodMessage ?? t("shared.common.error");
        }
    }

    return { moodMessage, moodLoading, moodError, saveMood };
}
