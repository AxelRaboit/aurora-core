import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/frontend/useRequest.js";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Generic paginated search composable for public frontend pages.
 *
 * @param {object} opts
 * @param {Array}  opts.initialItems       - First-page items from SSR props
 * @param {number} opts.initialPage
 * @param {number} opts.initialTotalPages
 * @param {number} opts.initialTotal
 * @param {string} opts.searchPath         - API endpoint URL
 * @param {string} opts.itemsKey           - Key in API response that holds the items array (e.g. 'posts', 'listings', 'items')
 */
export function usePaginatedSearch({
    initialItems,
    initialPage,
    initialTotalPages,
    initialTotal,
    searchPath,
    itemsKey,
}) {
    const items = ref(initialItems);
    const page = ref(initialPage);
    const totalPages = ref(initialTotalPages);
    const total = ref(initialTotal);
    const query = ref("");

    const { loading, request } = useRequest();

    async function fetchPage(searchTerm, pageNumber) {
        const searchParameters = new URLSearchParams({ page: pageNumber });
        if (searchTerm.trim()) searchParameters.set("q", searchTerm.trim());

        const data = await request(
            `${searchPath}?${searchParameters}`,
            null,
            HttpMethod.Get,
        );
        if (!data?.success) return;

        items.value = data[itemsKey];
        page.value = data.page;
        totalPages.value = data.totalPages;
        total.value = data.total;
    }

    const debouncedSearch = useDebounce(
        (searchTerm) => fetchPage(searchTerm, 1),
        300,
    );

    function onSearch(searchTerm) {
        query.value = searchTerm;
        debouncedSearch(searchTerm);
    }

    function goToPage(pageNumber) {
        page.value = pageNumber;
        fetchPage(query.value, pageNumber);
    }

    return {
        items,
        query,
        page,
        totalPages,
        total,
        loading,
        onSearch,
        goToPage,
    };
}
