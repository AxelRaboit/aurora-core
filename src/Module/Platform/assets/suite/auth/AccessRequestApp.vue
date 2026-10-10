<script setup>
import AppLink from "@/shared/components/nav/AppLink.vue";
import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { Send } from "lucide-vue-next";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppInput from "@/shared/components/form/input/AppInput.vue";
import { useAuthForm } from "@/shared/composables/form/useAuthForm.js";
import { required, email as emailValidator, compose } from "@/shared/utils/validation/validators.js";

const { t } = useI18n();

const props = defineProps({
    submitPath: { type: String, required: true },
    loginPath: { type: String, required: true },
    accessRequestEnabled: { type: Boolean, default: true },
    initialErrors: { type: Object, default: () => ({}) },
    values: { type: Object, default: () => ({}) },
});

const name = ref(props.values.name ?? "");
const email = ref(props.values.email ?? "");
const message = ref(props.values.message ?? "");
const { errors, submitOnValid } = useAuthForm(props.initialErrors);

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
    <div v-if="!accessRequestEnabled" class="space-y-2 text-center py-4">
        <p class="text-primary font-semibold">{{ t('suite.auth.access_request.closed_title') }}</p>
        <p class="text-secondary text-sm">{{ t('suite.auth.access_request.closed_desc') }}</p>
        <div class="mt-6 flex flex-col gap-2 text-sm">
            <AppLink :href="loginPath">{{ t('suite.auth.access_request.login_link') }}</AppLink>
        </div>
    </div>

    <template v-else>
        <h2 class="text-lg font-bold text-primary mb-2">{{ t('suite.auth.access_request.title') }}</h2>
        <p class="text-sm text-secondary mb-5">{{ t('suite.auth.access_request.description') }}</p>

        <form method="POST" :action="submitPath" class="flex flex-col gap-4" v-on:submit.prevent="handleSubmit">
            <AppInput
                v-model="name"
                name="name"
                :label="t('suite.auth.access_request.name')"
                :placeholder="t('suite.auth.access_request.name_placeholder')"
                :error="errors.name"
                autocomplete="name"
                autofocus
            />

            <AppInput
                v-model="email"
                name="email"
                type="email"
                :label="t('suite.auth.access_request.email')"
                :placeholder="t('suite.auth.login.email_placeholder')"
                :error="errors.email"
                autocomplete="email"
                required
            />

            <div>
                <label for="access-message" class="mb-1 block text-[0.8125rem] font-medium text-primary">{{ t('suite.auth.access_request.message') }}</label>
                <textarea
                    id="access-message"
                    v-model="message"
                    name="message"
                    rows="4"
                    :placeholder="t('suite.auth.access_request.message_placeholder')"
                    class="w-full px-3 py-2 border border-line rounded-md bg-surface text-sm text-primary placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-accent-500 focus:border-accent-500 transition resize-none"
                />
            </div>

            <AppButton type="submit"><Send class="w-4 h-4" :stroke-width="2" /> {{ t('suite.auth.access_request.submit') }}</AppButton>
        </form>

        <div class="mt-6 flex items-center gap-4">
            <div class="flex-1 border-t border-line" />
            <span class="text-sm text-secondary">{{ t('shared.common.or') }}</span>
            <div class="flex-1 border-t border-line" />
        </div>

        <p class="mt-4 text-center text-sm text-secondary">
            {{ t('suite.auth.access_request.already_account') }}
            <AppLink :href="loginPath">{{ t('suite.auth.access_request.login_link') }}</AppLink>
        </p>
    </template>
</template>
