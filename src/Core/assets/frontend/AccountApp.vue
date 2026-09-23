<script setup>
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";

const { t } = useI18n();

defineProps({
    logoutPath: { type: String, required: true },
    user: { type: Object, required: true },
});
</script>

<template>
    <div class="max-w-lg mx-auto py-12 px-4">
        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl font-bold text-primary">{{ t('frontend.account.heading') }}</h1>
            <form method="POST" :action="logoutPath">
                <AppButton type="submit" variant="ghost" size="sm" :class="'text-secondary hover:text-danger'">
                    {{ t('frontend.account.logout') }}
                </AppButton>
            </form>
        </div>

        <div class="aurora-card p-4 sm:p-6 space-y-4">
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-secondary uppercase tracking-wide">{{ t('frontend.account.name') }}</span>
                <span class="text-sm text-primary font-medium">{{ user.name }}</span>
            </div>
            <div class="flex flex-col gap-1">
                <span class="text-xs font-medium text-secondary uppercase tracking-wide">{{ t('frontend.account.email') }}</span>
                <span class="text-sm text-primary">{{ user.email }}</span>
            </div>
        </div>
    </div>
</template>
