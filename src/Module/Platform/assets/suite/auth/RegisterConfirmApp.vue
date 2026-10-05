<script setup>
import AppLink from "@/shared/components/nav/AppLink.vue";
import AppButton from "@/shared/components/action/AppButton.vue";
import { useI18n } from "vue-i18n";
import { Mail } from "lucide-vue-next";

const { t } = useI18n();

defineProps({
    loginPath: { type: String, required: true },
    resendPath: { type: String, required: true },
    pendingEmail: { type: String, default: "" },
    resent: { type: Boolean, default: false },
});
</script>

<template>
    <div class="text-center">
        <div class="w-14 h-14 rounded-full bg-emerald-500/15 flex items-center justify-center mx-auto mb-6">
            <Mail class="w-7 h-7 text-emerald-400" :stroke-width="2" />
        </div>
        <h1 class="text-2xl font-bold text-primary mb-3">{{ t('suite.auth.register_confirm.heading') }}</h1>
        <p class="text-secondary text-sm leading-relaxed mb-8">{{ t('suite.auth.register_confirm.message') }}</p>

        <AppLink :href="loginPath" class="text-sm">{{ t('suite.auth.register_confirm.login_link') }}</AppLink>

        <div v-if="pendingEmail" class="mt-8 pt-8 border-t border-line/40">
            <p v-if="resent" class="text-sm text-emerald-400">{{ t('suite.auth.register_confirm.resent_success') }}</p>
            <template v-else>
                <p class="text-sm text-secondary mb-3">{{ t('suite.auth.register_confirm.resend_hint') }}</p>
                <form method="POST" :action="resendPath">
                    <AppButton type="submit" variant="link-accent" size="none">
                        <Mail class="w-3.5 h-3.5" :stroke-width="2" />
                        {{ t('suite.auth.register_confirm.resend_link') }}
                    </AppButton>
                </form>
            </template>
        </div>
    </div>
</template>
