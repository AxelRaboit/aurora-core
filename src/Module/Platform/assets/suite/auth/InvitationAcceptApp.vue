<script setup>
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { UserCheck } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppPasswordStrength from "@/shared/components/form/input/AppPasswordStrength.vue";
import { useAuthForm } from "@/shared/composables/form/useAuthForm.js";
import { passwordValidator } from "@/shared/utils/validation/passwordRules.js";

const { t } = useI18n();

const props = defineProps({
    submitPath: { type: String, required: true },
    userName: { type: String, required: true },
    initialErrors: { type: Object, default: () => ({}) },
});

const password = ref("");
const passwordConfirm = ref("");
const { errors, submitOnValid } = useAuthForm(props.initialErrors);

function handleSubmit(event) {
    submitOnValid(event, {
        password: () => passwordValidator(t)(password.value),
        passwordConfirm: () => password.value === passwordConfirm.value
            ? null
            : t("suite.auth.invitation.errors.password_mismatch"),
    });
}
</script>

<template>
    <h1 class="text-xl font-bold text-primary mb-2">{{ t('suite.auth.invitation.welcome', { name: userName }) }}</h1>
    <p class="mb-4 text-sm text-secondary">{{ t('suite.auth.invitation.set_password') }}</p>

    <form method="POST" :action="submitPath" class="space-y-4" v-on:submit.prevent="handleSubmit">
        <div>
            <AppInput
                v-model="password"
                name="password"
                :label="t('suite.auth.invitation.password')"
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
            v-model="passwordConfirm"
            name="password_confirm"
            type="password"
            :label="t('suite.auth.invitation.confirm_password')"
            placeholder="••••••••"
            :error="errors.passwordConfirm"
            autocomplete="new-password"
            required
        />

        <div class="flex justify-end">
            <AppButton type="submit"><UserCheck class="w-4 h-4" :stroke-width="2" /> {{ t('suite.auth.invitation.submit') }}</AppButton>
        </div>
    </form>
</template>
