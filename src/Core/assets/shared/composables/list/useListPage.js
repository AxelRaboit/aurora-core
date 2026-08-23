import { ref, onMounted } from "vue";
import { usePaginatedFetch } from "@/shared/composables/http/backend/usePaginatedFetch.js";
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
 * @param {object}              opts            - { initialSearch, initialData, extraParams, searchParam, onData }
 * @param {string}              opts.initialSearch       Default search value (typically `props.search`)
 * @param {object|null}         opts.initialData         SSR-rendered first page payload (skip first XHR)
 * @param {() => object}        opts.extraParams         Extra URL params (filters, etc.)
 * @param {string}              opts.searchParam         URL query param name (default: "search")
 * @param {(data) => void}      opts.onData              Callback for extra payload fields
 */
export function useListPage(listPath, opts = {}) {
    const {
        initialSearch = "",
        initialData = null,
        extraParams = () => ({}),
        searchParam = "search",
        onData = null,
    } = opts;

    const search = ref(initialSearch);
    const syncSearchUrl = useUrlSearchSync(searchParam);

    const { items, loading, page, totalPages, total, load, goToPage, reset } =
        usePaginatedFetch(
            listPath,
            () => ({
                ...extraParams(),
                [searchParam]: search.value || undefined,
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
