/**
 * A Studio deliverable's categories, in the selector format: written once for
 * the creation dialog and for the settings, which each used to keep a copy.
 *
 * @param {Array<{id: number, name: string}>} categories
 */
export function categoryOptions(categories) {
    return categories.map((category) => ({
        value: category.id,
        label: category.name,
    }));
}
