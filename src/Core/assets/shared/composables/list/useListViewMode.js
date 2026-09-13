import { computed } from "vue";
import { useQueryState } from "@/shared/composables/useQueryState.js";
import {
    TABLE_MIN_WIDTH,
    useNarrowContainer,
} from "@/shared/composables/list/useNarrowContainer.js";

/**
 * List view toggle - "grid" ↔ "list", or whatever a caller declares.
 *
 * In the query string rather than localStorage, for the same reason as the
 * sort beside it: it describes the page being looked at, so it belongs to the
 * link. The default is left out of the URL so an untouched list has a clean
 * address.
 *
 * **The shape is decided by the container, never by the window.** See
 * {@see useNarrowContainer} for why that distinction is the whole point here.
 * Bind {@link container} to the element that holds the list.
 *
 * **The choice is overruled, never erased.** A narrow container renders cards
 * whatever the URL says; the stored choice stays in `storedViewMode` and comes
 * back the moment there is room for it.
 *
 * A list with no toggle wants {@see useNarrowContainer} on its own: there is
 * no choice to store, and pretending otherwise puts a value in the query
 * string that nothing ever sets.
 *
 * @param {string[]} [modes=["grid", "list"]] Allowed values; anything else is discarded.
 * @param {string}   [defaultMode="grid"]
 * @param {string}   [param="view"]           Query parameter name.
 */
export function useListViewMode(
    modes = ["grid", "list"],
    defaultMode = "grid",
    param = "view",
) {
    const { value, set } = useQueryState(param, {
        defaultValue: defaultMode,
        valid: modes,
    });

    const { container, isNarrow } = useNarrowContainer();

    const viewMode = computed(() =>
        // Only when there is something to fall back to: a list that declares
        // no card view has nothing better to offer and keeps what it has.
        isNarrow.value && modes.includes("grid") ? "grid" : value.value,
    );

    return {
        viewMode,
        setViewMode: set,
        /** What the reader chose, which a narrow container overrules without erasing. */
        storedViewMode: value,
        container,
        isNarrow,
        TABLE_MIN_WIDTH,
    };
}
