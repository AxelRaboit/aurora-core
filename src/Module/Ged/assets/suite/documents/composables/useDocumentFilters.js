import { ref, computed } from "vue";
import { useQueryState } from "@/shared/composables/useQueryState.js";

/** What the category and tag filters send for "without one". */
export const NONE = "none";

/** Where the search box looks. Mirrors DocumentSearchFieldEnum. */
export const SEARCH_FIELDS = ["all", "title", "file", "text", "classification"];

export function useDocumentFilters(reload) {
    const filterCategoryId = ref(null);
    const filterTagId = ref(null);
    const filterFolderId = ref(null);
    const filterStatus = ref(null);
    const filterMimeGroup = ref(null);
    // Only ever set on installations with a second backend; the screen hides
    // the control otherwise, and an unset filter costs nothing here.
    const filterStorageDisk = ref(null);
    // The wider search: which fields the box reads, and the filters that
    // came with it. "all" is the resting state, so it is not a filter.
    const searchIn = ref("all");
    const filterAddedFrom = ref("");
    const filterAddedTo = ref("");
    const filterOrientation = ref(null);
    const filterWeight = ref(null);
    /** How many of the filters behind « Plus de filtres » are set. */
    const moreFiltersCount = computed(
        () =>
            [
                "all" !== searchIn.value,
                filterAddedFrom.value,
                filterAddedTo.value,
                filterOrientation.value,
                filterWeight.value,
            ].filter(Boolean).length,
    );
    // Families folded to their original: each visual once, its variants a
    // click away on it. The default, because a third of a library made of
    // colour copies buries everything else; the flat view is kept in the
    // address (`familles=0`) so a reload or a shared link keeps it.
    const families = useQueryState("familles", {
        defaultValue: "1",
        valid: ["0", "1"],
    });
    const filterOriginalsOnly = computed({
        get: () => "0" !== families.value.value,
        set: (grouped) => families.set(grouped ? "1" : "0"),
    });
    const hasActiveFilter = computed(
        () =>
            !!(
                filterCategoryId.value ||
                filterTagId.value ||
                filterFolderId.value ||
                filterStatus.value ||
                filterMimeGroup.value ||
                filterStorageDisk.value ||
                moreFiltersCount.value ||
                // Grouped is the resting state: only leaving it is a filter.
                !filterOriginalsOnly.value
            ),
    );

    const extraParams = () => ({
        categoryId: filterCategoryId.value || undefined,
        tagId: filterTagId.value || undefined,
        folderId: filterFolderId.value || undefined,
        status: filterStatus.value || undefined,
        mimeGroup: filterMimeGroup.value || undefined,
        storageDisk: filterStorageDisk.value || undefined,
        originalsOnly: filterOriginalsOnly.value ? 1 : undefined,
        searchIn: "all" !== searchIn.value ? searchIn.value : undefined,
        addedFrom: filterAddedFrom.value || undefined,
        addedTo: filterAddedTo.value || undefined,
        orientation: filterOrientation.value || undefined,
        weight: filterWeight.value || undefined,
    });

    function applyFilter() {
        reload();
    }

    function resetFilters() {
        filterCategoryId.value = null;
        filterTagId.value = null;
        filterFolderId.value = null;
        filterStatus.value = null;
        filterMimeGroup.value = null;
        filterStorageDisk.value = null;
        searchIn.value = "all";
        filterAddedFrom.value = "";
        filterAddedTo.value = "";
        filterOrientation.value = null;
        filterWeight.value = null;
        filterOriginalsOnly.value = true;
        reload();
    }

    return {
        filterCategoryId,
        filterTagId,
        filterFolderId,
        filterStatus,
        filterMimeGroup,
        filterStorageDisk,
        filterOriginalsOnly,
        searchIn,
        filterAddedFrom,
        filterAddedTo,
        filterOrientation,
        filterWeight,
        moreFiltersCount,
        hasActiveFilter,
        extraParams,
        applyFilter,
        resetFilters,
    };
}
