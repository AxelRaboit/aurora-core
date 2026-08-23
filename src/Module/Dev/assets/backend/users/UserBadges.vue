<script setup>
import { useI18n } from "vue-i18n";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";

const { t } = useI18n();

defineProps({
    user: { type: Object, required: true },
});
</script>

<template>
    <AppBadge v-if="user.isCurrent" color="accent">{{ t("backend.users.you") }}</AppBadge>
    <AppBadge :color="user.roleColor">
        {{ user.isDevRole ? t("backend.users.role_dev") : t("backend.users.role_user") }}
    </AppBadge>
    <AppBadge color="gray" class="uppercase">{{ user.locale }}</AppBadge>
</template>
