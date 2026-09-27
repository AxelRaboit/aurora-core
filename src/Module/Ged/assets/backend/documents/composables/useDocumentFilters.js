import { ref, computed } from "vue";
import { useQueryState } from "@/shared/composables/useQueryState.js";

export function useDocumentFilters(reload) {
    const filterCategoryId = ref(null);
    const filterTagId = ref(null);
    const filterFolderId = ref(null);
    const filterStatus = ref(null);
    const filterMimeGroup = ref(null);
    // Only ever set on installations with a second backend; the screen hides
    // the control otherwise, and an unset filter costs nothing here.
    const filterStorageDisk = ref(null);
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
        hasActiveFilter,
        extraParams,
        applyFilter,
        resetFilters,
    };
}
