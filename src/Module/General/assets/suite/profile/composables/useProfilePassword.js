import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useForm } from "@/shared/composables/form/useForm.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { required } from "@/shared/utils/validation/validators.js";
import { passwordValidator } from "@/shared/utils/validation/passwordRules.js";

export function useProfilePassword(passwordPath) {
    const { t } = useI18n();
    const {
        errors: passwordErrors,
        validate: validatePassword,
        setErrors: setPasswordErrors,
        clearErrors: clearPasswordErrors,
    } = useForm();

    const currentPassword = ref("");
    const newPassword = ref("");
    const confirmPassword = ref("");
    const { loading: passwordLoading, request } = useRequest();

    async function savePassword() {
        const isValid = validatePassword({
            current_password: () =>
                required(t("suite.profile.errors.current_password_invalid"))(
                    currentPassword.value,
                ),
            password: () => passwordValidator(t)(newPassword.value),
            password_confirmation: () => {
                if (
                    newPassword.value &&
                    newPassword.value !== confirmPassword.value
                )
                    return t("suite.profile.errors.password_mismatch");
                return null;
            },
        });

        if (!isValid) return;

        const data = await request(passwordPath, {
            current_password: currentPassword.value,
            password: newPassword.value,
            password_confirmation: confirmPassword.value,
        });
        if (!data) return;
        if (data.success) {
            clearPasswordErrors();
            toast.success(t("suite.profile.password.saved"));
            currentPassword.value = "";
            newPassword.value = "";
            confirmPassword.value = "";
        } else {
            setPasswordErrors(data.errors ?? {});
        }
    }

    return {
        currentPassword,
        newPassword,
        confirmPassword,
        passwordLoading,
        passwordErrors,
        savePassword,
    };
}
