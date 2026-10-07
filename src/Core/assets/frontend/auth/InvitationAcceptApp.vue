<script setup>
/**
 * Setting your password when arriving through an invitation.
 *
 * The same shape as ResetPasswordApp - a token in the URL, a password to
 * choose - but not the same page, on purpose: "reset" is wrong for someone
 * who never had a password, and the person arrives here because they were
 * invited, not because they forgot something.
 *
 * Hence also the different fallback when the link is dead: the reset sends to
 * "forgot password", which is useless here - an invited account has no access
 * to recover yet. We send to the login page, and the text says to ask for a
 * new invitation.
 */
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { UserCheck } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import AppLink from "@/shared/components/nav/AppLink.vue";
import AuthCard from "@core/frontend/components/AuthCard.vue";
import { useAuthForm } from "@/shared/composables/form/useAuthForm.js";
import { passwordValidator } from "@/shared/utils/validation/passwordRules.js";

const { t } = useI18n();

const props = defineProps({
    submitPath: { type: String, required: true },
    loginPath: { type: String, required: true },
    invalid: { type: Boolean, default: false },
    initialErrors: { type: Object, default: () => ({}) },
    /** The first name entered by the administrator, to greet the person by name. */
    userName: { type: String, default: "" },
});

const password = ref("");
const passwordConfirmation = ref("");
const { errors, submitOnValid } = useAuthForm(props.initialErrors);

function handleSubmit(event) {
    submitOnValid(event, {
        password: () => passwordValidator(t)(password.value),
        password_confirmation: () => password.value === passwordConfirmation.value
            ? null
            : t("frontend.errors.passwords_mismatch"),
    });
}
</script>

<template>
    <AuthCard
        :heading="userName ? t('frontend.invitation.heading_named', {name: userName}) : t('frontend.invitation.heading')"
        :subtitle="invalid ? '' : t('frontend.invitation.subtitle')"
    >
        <template #icon><UserCheck class="w-6 h-6" :stroke-width="2" /></template>

        <template v-if="invalid">
            <div class="rounded-lg bg-danger-soft border border-danger/30 px-4 py-4 text-sm text-danger mb-6">
                {{ t('frontend.invitation.invalid_link') }}
            </div>
            <p class="text-center">
                <AppLink :href="loginPath" variant="front-accent" class="text-sm">{{ t('frontend.login.submit') }}</AppLink>
            </p>
        </template>

        <form
            v-else
            method="POST"
            :action="submitPath"
            class="space-y-5"
            v-on:submit.prevent="handleSubmit"
        >
            <AppInput
                v-model="password"
                name="password"
                :label="t('frontend.invitation.password')"
                placeholder="••••••••"
                :error="errors.password"
                autocomplete="new-password"
                toggleable
                autofocus
                required
            />
            <AppInput
                v-model="passwordConfirmation"
                name="password_confirmation"
                :label="t('frontend.invitation.confirm_password')"
                placeholder="••••••••"
                :error="errors.password_confirmation"
                autocomplete="new-password"
                toggleable
                required
            />
            <AppButton type="submit" class="w-full">
                <UserCheck class="w-4 h-4" :stroke-width="2" /> {{ t('frontend.invitation.submit') }}
            </AppButton>
        </form>
    </AuthCard>
</template>
