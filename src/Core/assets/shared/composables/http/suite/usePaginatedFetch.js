import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";

/**
 * Generic composable for paginated JSON fetch (XHR-based, no page reload).
 *
 * @param {string|(() => string)} getPath  - URL or function returning the URL (for dynamic paths)
 * @param {() => object}          getExtraParameters - Returns extra URLSearchParams entries (search, filter…)
 * @param {(data: object) => void} onData  - Optional callback to extract extra fields from the response
 */
export function usePaginatedFetch(
    getPath,
    getExtraParameters = () => ({}),
    onData = null,
    initialData = null,
) {
    const { t } = useI18n();

    const items = ref(initialData?.items ?? []);
    const loading = ref(false);
    const page = ref(initialData?.page ?? 1);
    const totalPages = ref(initialData?.totalPages ?? 1);
    const total = ref(initialData?.total ?? 0);

    async function load(targetPage = page.value) {
        const path = typeof getPath === "function" ? getPath() : getPath;
        if (!path) return;

        loading.value = true;
        try {
            const searchParameters = new URLSearchParams({
                page: String(targetPage),
            });
            for (const [key, value] of Object.entries(getExtraParameters())) {
                if (value !== undefined && value !== null && value !== "") {
                    searchParameters.set(key, String(value));
                }
            }
            const response = await fetch(`${path}?${searchParameters}`, {
                headers: { "X-Requested-With": "XMLHttpRequest" },
            });
            const data = await response.json();
            if (data.success) {
                items.value = data.items;
                total.value = data.total ?? 0;
                totalPages.value = data.totalPages ?? 1;
                page.value = data.page ?? targetPage;
                onData?.(data);
            } else {
                toast.error(t("shared.common.error"));
            }
        } catch {
            toast.error(t("shared.common.error"));
        } finally {
            loading.value = false;
        }
    }

    function goToPage(newPage) {
        page.value = newPage;
        load(newPage);
    }

    function reset() {
        page.value = 1;
        load(1);
    }

    return { items, loading, page, totalPages, total, load, goToPage, reset };
}
