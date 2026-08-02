/**
 * Fills `__name__` placeholders in a URL template with the provided values,
 * URI-encoding each one so callers don't have to think about special chars.
 *
 * Examples:
 *   buildPath("/backend/platform/users/__id__/edit", { id: 42 })
 *     → "/backend/platform/users/42/edit"
 *
 *   buildPath("/backend/ged/documents/__id__/versions/__versionId__", { id: 1, versionId: 7 })
 *     → "/backend/ged/documents/1/versions/7"
 *
 *   buildPath("/backend/parameters/__key__", { key: "site/name" })
 *     → "/backend/parameters/site%2Fname"
 *
 * @param {string} template
 * @param {Record<string, string|number>} params
 * @returns {string}
 */
export function buildPath(template, params) {
    let result = template;
    for (const [name, value] of Object.entries(params ?? {})) {
        result = result.replaceAll(
            `__${name}__`,
            encodeURIComponent(String(value)),
        );
    }
    return result;
}
