import { ref, watch } from "vue";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

export function useFormSubmissions(props, selected) {
    const { request } = useRequest();

    const submissions = ref([]);
    const total = ref(0);
    const page = ref(1);
    const totalPages = ref(1);
    const loading = ref(false);

    async function load() {
        if (!selected.value) return;

        loading.value = true;
        try {
            const data = await request(
                `${buildPath(props.submissionsPathTemplate, { id: selected.value.id })}?page=${page.value}`,
                null,
                { method: HttpMethod.Get, noGuard: true },
            );
            if (!data?.success) return;

            submissions.value = data.submissions;
            total.value = data.total;
            page.value = data.page;
            totalPages.value = data.totalPages;
        } finally {
            loading.value = false;
        }
    }

    // Switching form resets to the first page: staying on page 4 of a list
    // that now has one shows nothing and reads as "no submissions".
    watch(selected, () => {
        page.value = 1;
        submissions.value = [];
        void load();
    });

    function goToPage(next) {
        if (next < 1 || next > totalPages.value) return;

        page.value = next;
        void load();
    }

    function exportUrl() {
        return selected.value
            ? buildPath(props.exportPathTemplate, { id: selected.value.id })
            : "#";
    }

    return {
        submissions,
        total,
        page,
        totalPages,
        loading,
        load,
        goToPage,
        exportUrl,
    };
}
