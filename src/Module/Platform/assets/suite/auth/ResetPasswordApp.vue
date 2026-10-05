<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { KeyRound } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppPasswordStrength from "@/shared/components/form/input/AppPasswordStrength.vue";
import { useAuthForm } from "@/shared/composables/form/useAuthForm.js";
import { passwordValidator } from "@/shared/utils/validation/passwordRules.js";

const { t } = useI18n();

const props = defineProps({
    submitPath: { type: String, required: true },
    initialErrors: { type: Object, default: () => ({}) },
});

const password = ref("");
const passwordConfirmation = ref("");
const { errors, submitOnValid } = useAuthForm(props.initialErrors);

function handleSubmit(event) {
    submitOnValid(event, {
        password: () => passwordValidator(t)(password.value),
        password_confirmation: () => password.value === passwordConfirmation.value
            ? null
            : t("suite.auth.register.error_password_mismatch"),
    });
}
</script>

<template>
    <p class="mb-4 text-sm text-secondary">{{ t('suite.auth.reset_password.instructions') }}</p>

    <form method="POST" :action="submitPath" class="space-y-4" v-on:submit.prevent="handleSubmit">
        <div>
            <AppInput
                v-model="password"
                name="password"
                :label="t('suite.auth.register.password')"
                placeholder="••••••••"
                :error="errors.password"
                autocomplete="new-password"
                toggleable
                autofocus
                required
            />
            <AppPasswordStrength :password="password" />
        </div>

        <AppInput
            v-model="passwordConfirmation"
            name="password_confirmation"
            :label="t('suite.auth.register.password_confirm')"
            placeholder="••••••••"
            :error="errors.password_confirmation"
            autocomplete="new-password"
            toggleable
            required
        />

        <div class="flex justify-end">
            <AppButton type="submit"><KeyRound class="w-4 h-4" :stroke-width="2" /> {{ t('suite.auth.reset_password.submit') }}</AppButton>
        </div>
    </form>
</template>
