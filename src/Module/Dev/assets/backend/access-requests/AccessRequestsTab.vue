<script setup>
import { computed, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import AppButton from "@/shared/components/action/AppButton.vue";
import AppPageActions from "@/shared/components/action/AppPageActions.vue";
import AppModal from "@/shared/components/overlay/AppModal.vue";
import AppModalFooter from "@/shared/components/overlay/AppModalFooter.vue";
import AppLoader from "@/shared/components/feedback/AppLoader.vue";
import AppPagination from "@/shared/components/nav/AppPagination.vue";
import AppSearchInput from "@/shared/components/form/input/AppSearchInput.vue";
import { useDateFormat } from "@/shared/composables/format/useDateFormat.js";
import { Trash2, X, Check } from "lucide-vue-next";
import { useAccessRequests } from "./composables/useAccessRequests.js";
import AccessRequestStatusBadge from "./AccessRequestStatusBadge.vue";
import AccessRequestActions from "./AccessRequestActions.vue";

const { t } = useI18n();
const { formatDateShort } = useDateFormat();

const props = defineProps({
    accessRequestsPath: { type: String, required: true },
    accessRequestApprovePath: { type: String, required: true },
    accessRequestRejectPath: { type: String, required: true },
    accessRequestPurgePath: { type: String, required: true },
    csrfToken: { type: String, default: "" },
    initialData: { type: Object, default: null },
    initialSearch: { type: String, default: "" },
});

const accessRequests = useAccessRequests(
    props.accessRequestsPath,
    props.accessRequestApprovePath,
    props.accessRequestRejectPath,
    props.accessRequestPurgePath,
    props.csrfToken,
    props.initialData,
    props.initialSearch,
);

onMounted(() => {
    if (!accessRequests.items.value?.length) accessRequests.load();
});

// One entry and still a sheet: every list in the backend opens its actions the
// same way, and a toolbar's width belongs to the search, not to a verb.
const pageActions = computed(() => {
    return [
        {
            key: "purge",
            color: "rose",
            icon: Trash2,
            title: t("backend.access_requests.purge"),
            onSelect: () => (accessRequests.confirmPurge.value = true),
        },
    ];
});
</script>

<template>
    <div class="space-y-2 sm:space-y-4">
        <p class="text-sm text-secondary">{{ t('backend.access_requests.intro') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto] gap-2">
            <AppSearchInput
                v-model="accessRequests.searchInput.value"
                :placeholder="t('backend.access_requests.search_placeholder')"
                v-on:search="accessRequests.performSearch"
            />
            <AppPageActions :actions="pageActions" class="w-full sm:w-auto" />
        </div>

        <div class="relative space-y-4">
            <div class="sm:hidden space-y-3">
                <p v-if="!accessRequests.items.value?.length" class="py-8 text-center text-sm text-muted">{{ t('backend.access_requests.empty') }}</p>
                <div v-for="accessRequest in accessRequests.items.value" :key="accessRequest.id" class="aurora-card p-4 space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-medium text-primary truncate">{{ accessRequest.requesterName ?? '-' }}</p>
                            <p class="text-xs text-secondary truncate">{{ accessRequest.requesterEmail }}</p>
                        </div>
                        <AccessRequestStatusBadge
                            :access-request="accessRequest"
                            :status-label="accessRequests.statusLabel.value"
                            class="shrink-0"
                        />
                    </div>
                    <p v-if="accessRequest.message" class="text-sm text-secondary">{{ accessRequest.message }}</p>
                    <div class="flex items-center justify-between pt-1 border-t border-line">
                        <p class="text-xs text-muted">{{ formatDateShort(accessRequest.createdAt) }} · expire {{ formatDateShort(accessRequest.expiresAt) }}</p>
                        <div class="flex items-center gap-1">
                            <AccessRequestActions
                                :access-request="accessRequest"
                                v-on:approve="accessRequests.openApproveModal"
                                v-on:reject="(ar) => (accessRequests.pendingReject.value = ar)"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <div class="aurora-card hidden sm:block overflow-x-auto scrollbar-thin">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-surface-2/50 border-b border-line/40">
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.access_requests.requester') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden md:table-cell">{{ t('backend.access_requests.message') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.access_requests.status') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.access_requests.date') }}</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-muted hidden lg:table-cell">{{ t('backend.access_requests.expires') }}</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-muted">{{ t('backend.users.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line/40/40">
                        <tr v-for="accessRequest in accessRequests.items.value" :key="accessRequest.id" class="group hover:bg-surface-2/40 transition-colors">
                            <td class="px-4 py-2">
                                <p class="font-medium text-primary">{{ accessRequest.requesterName ?? '-' }}</p>
                                <p class="text-xs text-secondary">{{ accessRequest.requesterEmail }}</p>
                            </td>
                            <td class="px-4 py-2 max-w-xs hidden md:table-cell">
                                <p class="text-sm text-secondary truncate">{{ accessRequest.message ?? '-' }}</p>
                            </td>
                            <td class="px-4 py-2">
                                <AccessRequestStatusBadge
                                    :access-request="accessRequest"
                                    :status-label="accessRequests.statusLabel.value"
                                />
                            </td>
                            <td class="px-4 py-2 text-sm text-secondary hidden lg:table-cell">{{ formatDateShort(accessRequest.createdAt) }}</td>
                            <td class="px-4 py-2 text-sm text-secondary hidden lg:table-cell">{{ formatDateShort(accessRequest.expiresAt) }}</td>
                            <td class="px-4 py-2">
                                <div class="flex items-center justify-end gap-1">
                                    <AccessRequestActions
                                        :access-request="accessRequest"
                                        v-on:approve="accessRequests.openApproveModal"
                                        v-on:reject="(ar) => (accessRequests.pendingReject.value = ar)"
                                    />
                                </div>
                            </td>
                        </tr>
                        <tr v-if="!accessRequests.items.value?.length">
                            <td colspan="6" class="px-6 py-8 text-center text-sm text-muted">{{ t('backend.access_requests.empty') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <AppPagination
                v-if="accessRequests.totalPages.value > 1"
                :page="accessRequests.page.value"
                :total-pages="accessRequests.totalPages.value"
                v-on:change="accessRequests.goToPage"
            />
            <AppLoader :active="accessRequests.loading.value" />
        </div>

        <AppModal :show="!!accessRequests.pendingApprove.value" max-width="sm" :closeable="false" v-on:close="accessRequests.pendingApprove.value = null">
            <p class="text-sm text-primary">{{ t('backend.access_requests.approve_confirm', { name: accessRequests.pendingApprove.value?.requesterName ?? accessRequests.pendingApprove.value?.requesterEmail }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="accessRequests.pendingApprove.value = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="primary" size="md" v-on:click="accessRequests.doApproveRequest"><Check class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('backend.access_requests.approve') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal :show="!!accessRequests.pendingReject.value" max-width="sm" :closeable="false" v-on:close="accessRequests.pendingReject.value = null">
            <p class="text-sm text-primary">{{ t('backend.access_requests.reject_confirm', { name: accessRequests.pendingReject.value?.requesterName ?? accessRequests.pendingReject.value?.requesterEmail }) }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="accessRequests.pendingReject.value = null"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="danger" size="md" v-on:click="accessRequests.doRejectRequest"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('backend.access_requests.reject') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>

        <AppModal
            :show="accessRequests.confirmPurge.value"
            max-width="sm"
            :closeable="false"
            :title="t('shared.common.delete')"
            :icon="Trash2"
            v-on:close="accessRequests.confirmPurge.value = false"
        >
            <p class="text-sm text-primary">{{ t('backend.access_requests.purge_confirm') }}</p>
            <template #footer>
                <AppModalFooter>
                    <AppButton variant="ghost" size="md" v-on:click="accessRequests.confirmPurge.value = false"><X class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('shared.common.cancel') }}</AppButton>
                    <AppButton variant="danger" size="md" v-on:click="accessRequests.doPurge"><Trash2 class="w-3.5 h-3.5" :stroke-width="2" /> {{ t('backend.access_requests.purge') }}</AppButton>
                </AppModalFooter>
            </template>
        </AppModal>
    </div>
</template>
