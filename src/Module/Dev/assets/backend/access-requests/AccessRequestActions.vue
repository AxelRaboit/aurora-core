<script setup>
/**
 * What can be done about one access request.
 *
 * Only a pending one offers anything: approving an approved request or
 * rejecting a rejected one changes nothing, so the sheet is not offered at all
 * rather than offered empty.
 */
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import { Check, X } from "lucide-vue-next";
import AppRowActions from "@/shared/components/action/AppRowActions.vue";
import { AccessRequestStatus } from "@core/utils/enums/auth/accessRequestStatus.js";

const { t } = useI18n();

const props = defineProps({
    accessRequest: { type: Object, required: true },
});

const emit = defineEmits(["approve", "reject"]);

const actions = computed(() => [
    {
        key: "approve",
        color: "emerald",
        icon: Check,
        title: t("backend.access_requests.approve"),
        description: t("backend.access_requests.row_actions.approve_description"),
        onSelect: () => emit("approve", props.accessRequest),
    },
    {
        key: "reject",
        color: "rose",
        icon: X,
        title: t("backend.access_requests.reject"),
        description: t("backend.access_requests.row_actions.reject_description"),
        onSelect: () => emit("reject", props.accessRequest),
    },
]);
</script>

<template>
    <AppRowActions
        v-if="accessRequest.status === AccessRequestStatus.Pending"
        :actions="actions"
        :label="accessRequest.email ?? ''"
    />
</template>
