<script setup>
import AppLink from "@/shared/components/nav/AppLink.vue";
import { useI18n } from "vue-i18n";
import { Check, X } from "lucide-vue-next";

const { t } = useI18n();

defineProps({
    success: { type: Boolean, required: true },
    loginPath: { type: String, required: true },
    registerPath: { type: String, required: true },
});
</script>

<template>
    <div class="text-center">
        <template v-if="success">
            <div class="w-14 h-14 rounded-full bg-emerald-500/15 flex items-center justify-center mx-auto mb-6">
                <Check class="w-7 h-7 text-emerald-400" :stroke-width="2" />
            </div>
            <h1 class="text-2xl font-bold text-primary mb-3">{{ t('suite.auth.verify_email.success_heading') }}</h1>
            <p class="text-secondary text-sm leading-relaxed mb-8">{{ t('suite.auth.verify_email.success_message') }}</p>
            <AppLink :href="loginPath" class="text-sm">{{ t('suite.auth.login.submit') }}</AppLink>
        </template>
        <template v-else>
            <div class="w-14 h-14 rounded-full bg-rose-500/15 flex items-center justify-center mx-auto mb-6">
                <X class="w-7 h-7 text-rose-400" :stroke-width="2" />
            </div>
            <h1 class="text-2xl font-bold text-primary mb-3">{{ t('suite.auth.verify_email.error_heading') }}</h1>
            <p class="text-secondary text-sm leading-relaxed mb-8">{{ t('suite.auth.verify_email.error_message') }}</p>
            <AppLink :href="registerPath" class="text-sm">{{ t('suite.auth.register.submit') }}</AppLink>
        </template>
    </div>
</template>
