import { buildPath } from "@/shared/utils/http/buildPath.js";
import { ref, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { usePaginatedFetch } from "@/shared/composables/http/suite/usePaginatedFetch.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import {
    accessRequestStatusBadge,
    accessRequestStatusBadgeColor,
} from "@/shared/utils/format/statusStyles.js";
import { AccessRequestStatus } from "@core/utils/enums/auth/accessRequestStatus.js";

export function useAccessRequests(
    accessRequestsPath,
    approvePath,
    rejectPath,
    purgePath,
    csrfToken,
    initialAccessRequests,
    initialSearch = "",
) {
    const { t } = useI18n();

    const searchInput = ref(initialSearch);

    const { items, loading, page, totalPages, load, goToPage, reset } =
        usePaginatedFetch(
            () => accessRequestsPath,
            () => ({ search: searchInput.value || undefined }),
            null,
            initialAccessRequests,
        );

    function performSearch() {
        reset();
    }

    onMounted(() => {
        if (!initialAccessRequests?.items?.length) {
            load();
        }
    });

    const statusLabel = ref({
        [AccessRequestStatus.Pending]: t(
            "suite.access_requests.status_pending",
        ),
        [AccessRequestStatus.Approved]: t(
            "suite.access_requests.status_approved",
        ),
        [AccessRequestStatus.Rejected]: t(
            "suite.access_requests.status_rejected",
        ),
    });

    const pendingApprove = ref(null);
    const pendingReject = ref(null);
    const confirmPurge = ref(false);
    const { loading: acting, request } = useRequest();

    function openApproveModal(accessRequest) {
        pendingApprove.value = accessRequest;
    }

    async function doApproveRequest() {
        if (!pendingApprove.value || acting.value) return;
        const url = buildPath(approvePath, { id: pendingApprove.value.id });
        const data = await request(url);
        if (!data) return;
        if (data.success) {
            toast.success(
                data.message ?? t("suite.access_requests.approved_toast"),
            );
            pendingApprove.value = null;
            await reset();
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    async function doRejectRequest() {
        if (!pendingReject.value || acting.value) return;
        const url = buildPath(rejectPath, { id: pendingReject.value.id });
        const data = await request(url);
        if (!data) return;
        if (data.success) {
            toast.success(
                data.message ?? t("suite.access_requests.rejected_toast"),
            );
            pendingReject.value = null;
            await reset();
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    async function doPurge() {
        if (acting.value) return;
        const data = await request(purgePath);
        if (!data) return;
        if (data.success) {
            toast.success(
                data.message ?? t("suite.access_requests.purged_toast"),
            );
            confirmPurge.value = false;
            await reset();
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    return {
        items,
        loading,
        page,
        totalPages,
        goToPage,
        load,
        reset,
        searchInput,
        performSearch,
        statusBadge: accessRequestStatusBadge,
        statusBadgeColor: accessRequestStatusBadgeColor,
        statusLabel,
        pendingApprove,
        pendingReject,
        confirmPurge,
        acting,
        openApproveModal,
        doApproveRequest,
        doRejectRequest,
        doPurge,
    };
}
