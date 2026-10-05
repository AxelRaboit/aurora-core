<script setup>
import { Check, Clock, ShieldCheck, X } from "lucide-vue-next";
import { AccessRequestStatus } from "@core/utils/enums/auth/accessRequestStatus.js";
import AppBadge from "@/shared/components/feedback/AppBadge.vue";
import { accessRequestStatusBadgeColor } from "@/shared/utils/format/statusStyles.js";

defineProps({
    accessRequest: { type: Object, required: true },
    statusLabel: { type: Object, required: true },
});

const STATUS_ICONS = { [AccessRequestStatus.Pending]: Clock, [AccessRequestStatus.Approved]: ShieldCheck, [AccessRequestStatus.Rejected]: X };
</script>

<template>
    <AppBadge :color="accessRequestStatusBadgeColor(accessRequest.status)">
        <component :is="STATUS_ICONS[accessRequest.status] ?? Check" class="w-3 h-3" :stroke-width="2.5" />
        {{ statusLabel[accessRequest.status] ?? accessRequest.status }}
    </AppBadge>
</template>
