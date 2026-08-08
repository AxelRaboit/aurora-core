import { useQueryState } from "@/shared/composables/useQueryState.js";

/**
 * Sort state for a list: a `sortBy` field + `sortDir` (asc/desc). Clicking the
 * same field toggles direction; clicking a new one resets to asc.
 *
 * Both live in the query string rather than in localStorage. A sort is part of
 * what someone is looking at, not of how they like to work: a colleague asked
 * to "check the documents, newest first" should be able to receive that as a
 * link. Remembering it locally also meant two browser tabs on two lists
 * overwriting each other, and there was no way to get back to the default
 * order except by clicking through to it.
 *
 * Defaults are left out of the URL, so a list nobody has sorted has a clean
 * address.
 *
 * @param {string}       [defaultField="name"]
 * @param {"asc"|"desc"} [defaultDir="asc"]
 * @param {object}       [options]
 * @param {string}       [options.fieldParam="sort"] Query parameter for the field.
 * @param {string}       [options.dirParam="dir"]    Query parameter for the direction.
 */
export function useListSort(
    defaultField = "name",
    defaultDir = "asc",
    { fieldParam = "sort", dirParam = "dir" } = {},
) {
    const field = useQueryState(fieldParam, { defaultValue: defaultField });
    const direction = useQueryState(dirParam, {
        defaultValue: defaultDir,
        valid: ["asc", "desc"],
    });

    function setSort(next) {
        if (field.value.value === next) {
            direction.set("asc" === direction.value.value ? "desc" : "asc");

            return;
        }

        field.set(next);
        direction.set("asc");
    }

    return { sortBy: field.value, sortDir: direction.value, setSort };
}
