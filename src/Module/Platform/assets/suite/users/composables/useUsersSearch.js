import { ref, onMounted, watch } from "vue";
import { useDebounce } from "@/shared/composables/useDebounce.js";
import { usePaginatedFetch } from "@/shared/composables/http/suite/usePaginatedFetch.js";

export function useUsersSearch(listPath) {
    const search = ref("");
    const roleFilter = ref("");

    const {
        items: users,
        loading,
        page,
        totalPages,
        total,
        load: fetchUsers,
        goToPage,
        reset: resetUsers,
    } = usePaginatedFetch(
        () => listPath,
        () => ({
            ...(search.value && { search: search.value }),
            ...(roleFilter.value && { role: roleFilter.value }),
        }),
    );

    onMounted(fetchUsers);
    watch([search, roleFilter], useDebounce(resetUsers, 300));

    return {
        search,
        roleFilter,
        users,
        loading,
        page,
        totalPages,
        total,
        fetchUsers,
        goToPage,
    };
}
