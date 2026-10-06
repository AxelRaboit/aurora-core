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
 * @param {Array<{id: number, title: string, template?: boolean, category?: {id: number}|null}>} rows
 */
export function templateOptions(rows) {
    const seen = new Set();

    return rows
        .filter((row) => row.template && !seen.has(row.id) && seen.add(row.id))
        .map((row) => ({
            value: row.id,
            label: row.title,
            categoryId: row.category?.id ?? null,
        }))
        .sort((left, right) => left.label.localeCompare(right.label));
}
