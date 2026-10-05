/**
 * Les catégories d'un livrable de Studio, au format des sélecteurs : écrit une
 * fois pour la fenêtre de création et pour les réglages, qui en gardaient
 * chacun une copie.
 *
 * @param {Array<{id: number, name: string}>} categories
 */
export function categoryOptions(categories) {
    return categories.map((category) => ({
        value: category.id,
        label: category.name,
    }));
}
