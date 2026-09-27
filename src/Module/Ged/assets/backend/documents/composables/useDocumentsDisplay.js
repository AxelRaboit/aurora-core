import { computed } from "vue";
import { useListViewMode } from "@/shared/composables/list/useListViewMode.js";
import { useListSort } from "@/shared/composables/list/useListSort.js";

/**
 * Display state for /backend/ged/documents:
 *   - view mode toggle (grid ↔ list, persisted)
 *   - sort by name | size | date (persisted, asc/desc toggle on re-click)
 *
 * Pass the raw items list reactively; consumers get a `displayedItems`
 * computed they iterate directly. The sort itself is the server's: sorting
 * here only ever reordered the twenty rows of the page on screen, and it
 * pulled a family apart that the server keeps together. `sortParams()`
 * hands the choice to the listing request.
 */
export const DOCUMENT_SORT_FIELDS = [
    { key: "date", labelKey: "shared.common.dates" },
    { key: "name", label: "A-Z" },
    { key: "size", label: "KB" },
];

export function useDocumentsDisplay(items) {
    // Both in the query string: the sort and the layout describe what is on
    // screen, so a link to this list carries them.
    const { viewMode, setViewMode, storedViewMode, container, isNarrow } =
        useListViewMode(["grid", "list"], "list");
    const { sortBy, sortDir, setSort } = useListSort("date", "desc", {
        // Named after the columns rather than the module: nothing else on this
        // page competes for them, and ?sort=date reads better than
        // ?gedSort=date in a link someone is meant to click.
        fieldParam: "sort",
        dirParam: "dir",
    });

    const displayedItems = computed(() => items.value);

    const sortParams = () => ({
        sort: sortBy.value,
        direction: sortDir.value,
    });

    return {
        viewMode,
        setViewMode,
        storedViewMode,
        container,
        isNarrow,
        sortBy,
        sortDir,
        setSort,
        sortParams,
        displayedItems,
    };
}
