/**
 * Fills `__name__` placeholders in a URL template with the provided values,
 * URI-encoding each one so callers don't have to think about special chars.
 *
 * Examples:
 *   buildPath("/suite/platform/users/__id__/edit", { id: 42 })
 *     → "/suite/platform/users/42/edit"
 *
 *   buildPath("/suite/ged/documents/__id__/versions/__versionId__", { id: 1, versionId: 7 })
 *     → "/suite/ged/documents/1/versions/7"
 *
 *   buildPath("/suite/parameters/__key__", { key: "site/name" })
 *     → "/suite/parameters/site%2Fname"
 *
 * @param {string} template
 * @param {Record<string, string|number>} parameters
 * @returns {string}
 */
export function buildPath(template, parameters) {
    let result = template;
    for (const [name, value] of Object.entries(parameters ?? {})) {
        result = result.replaceAll(
            `__${name}__`,
            encodeURIComponent(String(value)),
        );
    }
    return result;
}
