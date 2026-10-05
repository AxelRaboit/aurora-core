/**
 * Les dossiers dépliés, retenus dans le navigateur.
 *
 * Une habitude de lecture, pas un état du carnet : elle ne regarde que la
 * personne assise là, et le panneau comme le lecteur la partagent, pour qu'on
 * retrouve les mêmes dossiers ouverts d'un espace à l'autre. Un stockage
 * indisponible ou abîmé donne un arbre replié, un défaut d'agrément et pas
 * une panne.
 */
export const EXPANDED_KEY = "aurora.notes.panel.expanded";

/** @returns {Set<number>} */
export function readExpanded() {
    try {
        const ids = JSON.parse(
            window.localStorage.getItem(EXPANDED_KEY) ?? "[]",
        );

        return new Set(Array.isArray(ids) ? ids.map(Number) : []);
    } catch {
        return new Set();
    }
}

/** @param {Set<number>} ids */
export function storeExpanded(ids) {
    try {
        window.localStorage.setItem(EXPANDED_KEY, JSON.stringify([...ids]));
    } catch {
        // Idem : la session continue sans mémoire.
    }
}
