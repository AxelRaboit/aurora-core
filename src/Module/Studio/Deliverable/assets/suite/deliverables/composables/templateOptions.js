/**
 * The templates a new deliverable can start from, in the selector format, by
 * title.
 *
 * Read from the shelves already loaded rather than asked of the server: the
 * list is there, a template is one of its rows, and a second source would be
 * a second thing that can be wrong about what is a template. The category
 * follows the option, so that the creation dialog picks it up when a
 * template is chosen.
 *
 * A given format only keeps the templates of that format: you do not start
 * from a page to write a presentation. A row without a format is a page, as
 * the server says of a request without a format.
 *
 * @param {Array<{id: number, title: string, format?: string, template?: boolean, category?: {id: number}|null}>} rows
 * @param {string|null} [format] `page` or `slides`; absent, every template
 */
export function templateOptions(rows, format = null) {
    const seen = new Set();

    return rows
        .filter((row) => !format || (row.format ?? "page") === format)
        .filter((row) => row.template && !seen.has(row.id) && seen.add(row.id))
        .map((row) => ({
            value: row.id,
            label: row.title,
            categoryId: row.category?.id ?? null,
        }))
        .sort((left, right) => left.label.localeCompare(right.label));
}
