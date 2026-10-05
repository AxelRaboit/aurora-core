<script setup>
import AppLink from "@/shared/components/nav/AppLink.vue";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Mail } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import { useAuthForm } from "@/shared/composables/form/useAuthForm.js";
import { required, email as emailValidator, compose } from "@/shared/utils/validation/validators.js";

const { t } = useI18n();

defineProps({
    submitPath: { type: String, required: true },
    loginPath: { type: String, required: true },
    status: { type: String, default: "" },
});

const email = ref("");
const { errors, submitOnValid } = useAuthForm();

function handleSubmit(event) {
    submitOnValid(event, {
        email: () => compose(
            required(t("suite.auth.register.error_email_required")),
            emailValidator(t("suite.auth.register.error_email_invalid")),
        )(email.value),
    });
}
</script>

<template>
    <div v-if="status" class="mb-4 text-sm font-medium text-green-500">{{ status }}</div>

    <template v-if="!status">
        <p class="mb-4 text-sm text-secondary">{{ t('suite.auth.forgot_password.instructions') }}</p>

        <form method="POST" :action="submitPath" class="space-y-4" v-on:submit.prevent="handleSubmit">
            <AppInput
                v-model="email"
                name="email"
                type="email"
                :label="t('suite.auth.login.email')"
                :placeholder="t('suite.auth.login.email_placeholder')"
                :error="errors.email"
                autocomplete="email"
                autofocus
                required
            />
            <AppButton type="submit" class="w-full"><Mail class="w-4 h-4" :stroke-width="2" /> {{ t('suite.auth.forgot_password.submit') }}</AppButton>
        </form>
    </template>

    <div class="mt-6 flex items-center gap-4">
        <div class="flex-1 border-t border-line" />
        <span class="text-sm text-secondary">{{ t('shared.common.or') }}</span>
        <div class="flex-1 border-t border-line" />
    </div>

    <div class="mt-4 text-center">
        <AppLink :href="loginPath" class="text-sm">{{ t('suite.auth.forgot_password.back_login') }}</AppLink>
    </div>
</template>
