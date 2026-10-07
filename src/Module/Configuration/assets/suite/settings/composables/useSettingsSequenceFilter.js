import { ref, computed, watch } from "vue";
import { useLocalPagination } from "@/shared/composables/list/useLocalPagination.js";

export function useSettingsSequenceFilter(groups) {
    const sequenceSearch = ref("");

    const filteredSequences = computed(() => {
        const query = sequenceSearch.value.toLowerCase().trim();
        if (!query) return groups["sequences"] ?? [];
        return (groups["sequences"] ?? []).filter(
            (sequence) =>
                sequence.label.toLowerCase().includes(query) ||
                sequence.key.toLowerCase().includes(query),
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
