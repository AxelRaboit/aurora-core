import { ref, computed } from "vue";

export function useDocumentFilters(reload) {
    const filterCategoryId = ref(null);
    const filterTagId = ref(null);
    const filterFolderId = ref(null);
    const filterStatus = ref(null);
    const filterMimeGroup = ref(null);
    // Only ever set on installations with a second backend; the screen hides
    // the control otherwise, and an unset filter costs nothing here.
    const filterStorageDisk = ref(null);
    const hasActiveFilter = computed(
        () =>
            !!(
                filterCategoryId.value ||
                filterTagId.value ||
                filterFolderId.value ||
                filterStatus.value ||
                filterMimeGroup.value ||
                filterStorageDisk.value
            ),
    );

    const extraParams = () => ({
        categoryId: filterCategoryId.value || undefined,
        tagId: filterTagId.value || undefined,
        folderId: filterFolderId.value || undefined,
        status: filterStatus.value || undefined,
        mimeGroup: filterMimeGroup.value || undefined,
        storageDisk: filterStorageDisk.value || undefined,
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
        reload();
    }

    return {
        filterCategoryId,
        filterTagId,
        filterFolderId,
        filterStatus,
        filterMimeGroup,
        filterStorageDisk,
        hasActiveFilter,
        extraParams,
        applyFilter,
        resetFilters,
    };
}
