import { ref, onMounted } from "vue";
import { usePaginatedFetch } from "@/shared/composables/http/suite/usePaginatedFetch.js";
import { useUrlSearchSync } from "@/shared/composables/list/useUrlSearchSync.js";

/**
 * Bundles the canonical CRUD list-page wiring:
 *   1. paginated XHR fetch (no reload)
 *   2. debounced search input synced to ?search=... in the URL
 *   3. reset to page 1 when search changes
 *
 * Returns { items, loading, page, totalPages, total, search, onSearch, goToPage, reload, load }.
 *
 * @param {string|(()=>string)} listPath        - list endpoint URL or factory
 * @param {object}              options         - { initialSearch, initialData, extraParams, searchParam, onData }
 * @param {string}              options.initialSearch    Default search value (typically `props.search`)
 * @param {object|null}         options.initialData      SSR-rendered first page payload (skip first XHR)
 * @param {() => object}        options.extraParams      Extra URL params (filters, etc.)
 * @param {string}              options.searchParam      URL query param name (default: "search")
 * @param {(data) => void}      options.onData           Callback for extra payload fields
 */
export function useListPage(listPath, options = {}) {
    const {
        initialSearch = "",
        initialData = null,
        extraParams: extraParameters = () => ({}),
        searchParam: searchParameter = "search",
        onData = null,
    } = options;

    const search = ref(initialSearch);
    const syncSearchUrl = useUrlSearchSync(searchParameter);

    const { items, loading, page, totalPages, total, load, goToPage, reset } =
        usePaginatedFetch(
            listPath,
            () => ({
                ...extraParameters(),
                [searchParameter]: search.value || undefined,
            }),
            onData,
            initialData,
        );

    function onSearch(value) {
        search.value = value;
        syncSearchUrl(value);
        reset();
    }

    // Auto-load on mount only when no SSR initial data was provided.
    if (!initialData) {
        onMounted(() => load());
    }

    return {
        items,
        loading,
        page,
        totalPages,
        total,
        search,
        onSearch,
        goToPage,
        reload: reset,
        load,
    };
}
