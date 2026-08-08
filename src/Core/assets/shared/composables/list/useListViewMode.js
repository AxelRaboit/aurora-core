import { useQueryState } from "@/shared/composables/useQueryState.js";

/**
 * List view toggle — "grid" ↔ "list", or whatever a caller declares.
 *
 * In the query string rather than localStorage, for the same reason as the
 * sort beside it: it describes the page being looked at, so it belongs to the
 * link. The default is left out of the URL so an untouched list has a clean
 * address.
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

    return { viewMode: value, setViewMode: set };
}
