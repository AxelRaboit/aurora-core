import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/** The answers a form received, a page at a time. */
export function useFormSubmissions(props) {
    const { request } = useRequest();

    const submissions = ref([]);
    const total = ref(0);
    const page = ref(1);
    const totalPages = ref(1);
    const loading = ref(false);

    async function load() {
        loading.value = true;
        try {
            const data = await request(
                `${props.submissionsPath}?page=${page.value}`,
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

    function goToPage(next) {
        if (next < 1 || next > totalPages.value) return;

        page.value = next;
        void load();
    }

    function exportUrl() {
        return props.exportPath;
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
