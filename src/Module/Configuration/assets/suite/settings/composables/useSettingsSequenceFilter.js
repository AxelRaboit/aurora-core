import { ref, computed, watch } from "vue";
import { useLocalPagination } from "@/shared/composables/list/useLocalPagination.js";

export function useSettingsSequenceFilter(groups) {
    const sequenceSearch = ref("");

    const filteredSequences = computed(() => {
        const q = sequenceSearch.value.toLowerCase().trim();
        if (!q) return groups["sequences"] ?? [];
        return (groups["sequences"] ?? []).filter(
            (p) =>
                p.label.toLowerCase().includes(q) ||
                p.key.toLowerCase().includes(q),
        );
    });

    const {
        page: sequencePage,
        totalPages: sequenceTotalPages,
        paginatedItems: paginatedSequences,
        goToPage: goToSequencePage,
    } = useLocalPagination(filteredSequences, 10);

    watch(sequenceSearch, () => goToSequencePage(1));

    return {
        sequenceSearch,
        paginatedSequences,
        sequencePage,
        sequenceTotalPages,
        goToSequencePage,
    };
}
