/**
 * Adds query parameters to a path that may already carry some.
 *
 * Paths handed down by the server are not always bare: a note screen hosted
 * by another module receives every engine path with the host's parameter
 * already in it (`?notesHost=…`). Appending `?q=…` by hand to such a path
 * gave `?notesHost=…?q=…`, which the server reads as one value; this joins
 * with `&` when a query is already there. Empty values are left out, so a
 * caller can pass optional parameters as they are.
 *
 * Examples:
 *   withQuery("/suite/notes/markdown/search", { q: "brief" })
 *     → "/suite/notes/markdown/search?q=brief"
 *
 *   withQuery("/suite/notes/markdown/search?notesHost=studio.customer_space%3A12", { q: "brief" })
 *     → "/suite/notes/markdown/search?notesHost=studio.customer_space%3A12&q=brief"
 *
 * @param {string} path
 * @param {Record<string, string|number|boolean|null|undefined>} parameters
 * @returns {string}
 */
export function withQuery(path, parameters) {
    const query = new URLSearchParams();
    for (const [name, value] of Object.entries(parameters ?? {})) {
        if (null === value || undefined === value || "" === value) continue;
        query.append(name, String(value));
    }

    const encoded = query.toString();
    if ("" === encoded) return path;

    const [base, hash] = String(path).split("#", 2);
    const joined = `${base}${base.includes("?") ? "&" : "?"}${encoded}`;

    return undefined === hash ? joined : `${joined}#${hash}`;
}
