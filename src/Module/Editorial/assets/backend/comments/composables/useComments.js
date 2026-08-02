import { computed, onMounted, ref, watch } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

export function useComments(props) {
    const { t } = useI18n();
    const { request } = useRequest();

    const comments = ref([]);
    const counts = ref({ ...props.counts });
    const total = ref(0);
    const page = ref(1);
    const totalPages = ref(1);
    const status = ref("");
    const search = ref("");
    const loading = ref(false);

    const pendingCount = computed(() => counts.value.pending ?? 0);

    async function load() {
        loading.value = true;
        try {
            const params = new URLSearchParams({ page: String(page.value) });
            if (status.value) params.set("status", status.value);
            if (search.value.trim()) params.set("search", search.value.trim());

            const data = await request(`${props.listPath}?${params}`, null, {
                method: HttpMethod.Get,
                noGuard: true,
            });
            if (!data?.success) return;

            comments.value = data.comments;
            counts.value = data.counts;
            total.value = data.total;
            page.value = data.page;
            totalPages.value = data.totalPages;
        } finally {
            loading.value = false;
        }
    }

    onMounted(load);

    // Changing the filter always returns to the first page: staying on page 4
    // of a list that now has two is how a moderator concludes the queue is
    // empty when it is not.
    watch(status, () => {
        page.value = 1;
        void load();
    });

    const debouncedSearch = useDebounce(() => {
        page.value = 1;
        void load();
    }, 300);

    watch(search, debouncedSearch);

    function goToPage(next) {
        if (next < 1 || next > totalPages.value) return;

        page.value = next;
        void load();
    }

    async function moderate(comment, action, messageKey) {
        const data = await request(
            buildPath(props[`${action}PathTemplate`], { id: comment.id }),
        );
        if (!data?.success) return;

        toast.success(t(messageKey));
        await load();
    }

    const approve = (comment) =>
        moderate(comment, "approve", "backend.comments.approved");
    const markAsSpam = (comment) =>
        moderate(comment, "spam", "backend.comments.marked_spam");

    const pendingDelete = ref(null);
    const deleteLoading = ref(false);

    async function doDelete() {
        if (deleteLoading.value || !pendingDelete.value) return;

        deleteLoading.value = true;
        try {
            const data = await request(
                buildPath(props.deletePathTemplate, {
                    id: pendingDelete.value.id,
                }),
            );
            if (data?.success) {
                toast.success(t("backend.comments.deleted"));
                pendingDelete.value = null;
                await load();
            }
        } finally {
            deleteLoading.value = false;
        }
    }

    return {
        comments,
        counts,
        pendingCount,
        total,
        page,
        totalPages,
        status,
        search,
        loading,
        goToPage,
        approve,
        markAsSpam,
        pendingDelete,
        deleteLoading,
        doDelete,
    };
}
