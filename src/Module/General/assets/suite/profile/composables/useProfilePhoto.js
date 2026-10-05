import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";

export function useProfilePhoto(uploadPath, deletePath, initialPhotoUrl = "") {
    const { t } = useI18n();
    const photoUrl = ref(initialPhotoUrl);
    const { loading: photoLoading, request } = useRequest();

    async function onPhotoSelected(file) {
        if (!file) return;
        const formData = new FormData();
        formData.append("photo", file);
        const data = await request(uploadPath, null, { rawBody: formData });
        if (!data) return;
        if (!data.success) {
            toast.error(
                t(data.errors?.photo ?? data.error ?? "shared.common.error"),
            );
            return;
        }
        photoUrl.value = data.profilePhotoUrl ?? "";
        toast.success(t("suite.users.photo.uploaded"));
    }

    async function removePhoto() {
        const data = await request(deletePath);
        if (!data) return;
        if (!data.success) {
            toast.error(t(data.error ?? "shared.common.error"));
            return;
        }
        photoUrl.value = "";
        toast.success(t("suite.users.photo.removed"));
    }

    return { photoUrl, photoLoading, onPhotoSelected, removePhoto };
}
