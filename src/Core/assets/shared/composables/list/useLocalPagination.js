import { ref, computed, isRef } from "vue";

export function useLocalPagination(source, perPage = 10) {
    const page = ref(1);
    const items = isRef(source) ? source : computed(() => source);
    const totalPages = computed(() => Math.ceil(items.value.length / perPage));
    const paginatedItems = computed(() => {
        const start = (page.value - 1) * perPage;
        return items.value.slice(start, start + perPage);
    });

    function goToPage(pageNumber) {
        page.value = pageNumber;
    }

    return { page, totalPages, paginatedItems, goToPage };
}
