/**
 * Les modèles qu'on peut prendre pour départ d'un livrable neuf, au format des
 * sélecteurs, par titre.
 *
 * Lus dans les rayons déjà chargés plutôt que demandés au serveur : la liste
 * est là, un modèle en est une ligne, et une seconde source serait une seconde
 * chose qui peut se tromper sur ce qui est un modèle. La catégorie suit
 * l'option, pour que la fenêtre de création la reprenne quand on choisit un
 * modèle.
 *
 * Un format donné ne garde que les modèles de ce format : on ne part pas
 * d'une page pour écrire une présentation. Une ligne sans format est une page,
 * comme le dit le serveur d'un envoi sans format.
 *
 * @param {Array<{id: number, title: string, format?: string, template?: boolean, category?: {id: number}|null}>} rows
 * @param {string|null} [format] `page` ou `slides` ; absent, tous les modèles
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
