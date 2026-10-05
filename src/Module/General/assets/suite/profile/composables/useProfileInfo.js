import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useForm } from "@/shared/composables/form/useForm.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import {
    required,
    email,
    compose,
} from "@/shared/utils/validation/validators.js";

export function useProfileInfo(updatePath, initialName, initialEmail) {
    const { t } = useI18n();
    const {
        errors: infoErrors,
        validate: validateInfo,
        setErrors: setInfoErrors,
        clearErrors: clearInfoErrors,
    } = useForm();

    const infoName = ref(initialName);
    const infoEmail = ref(initialEmail);
    const { loading: infoLoading, request } = useRequest();

    async function saveInfo() {
        const isValid = validateInfo({
            name: () =>
                required(t("suite.profile.errors.name_required"))(
                    infoName.value,
                ),
            email: () =>
                compose(
                    required(t("suite.profile.errors.email_invalid")),
                    email(t("suite.profile.errors.email_invalid")),
                )(infoEmail.value),
        });
        if (!isValid) return;

        const data = await request(updatePath, {
            name: infoName.value,
            email: infoEmail.value,
        });
        if (!data) return;
        if (data.success) {
            clearInfoErrors();
            toast.success(t("suite.profile.info.saved"));
        } else {
            setInfoErrors(data.errors ?? {});
        }
    }

    return { infoName, infoEmail, infoLoading, infoErrors, saveInfo };
}
