/**
 * Le corps d'une note, sans le titre qu'elle répète en tête.
 *
 * Une note d'Aurora porte son titre dans un champ, et presque toutes le
 * réécrivent en `# Titre` sur leur première ligne - c'est ce que fait un
 * éditeur markdown quand rien ne tient le titre à sa place. Partout où
 * l'écran affiche déjà le titre à côté du corps (la carte, la page de
 * lecture, l'aperçu de l'éditeur), il se lisait donc deux fois de suite.
 *
 * **Le texte n'est pas touché.** On ne retire rien en base, rien à
 * l'export, rien dans la zone d'écriture : c'est le rendu qui cesse de dire
 * deux fois la même chose. Un titre changé plus tard fait simplement
 * réapparaître le `# ` d'origine dans le rendu, ce qui est la bonne
 * surprise - le texte de la note n'a pas bougé sous les pieds de son
 * auteur.
 *
 * Et seulement quand les deux disent exactement la même chose : un
 * document dont la première ligne est un vrai titre différent garde son
 * titre.
 */

/** `# Titre` en tête, avec les lignes vides qui le précèdent ou le suivent. */
const LEADING_HEADING = /^\s*#\s+(.+?)\s*(?:\n+|$)/;

/**
 * @param {string} markdown le corps de la note
 * @param {string} title ce que l'écran affiche déjà à côté
 * @returns {string} le corps, moins son titre répété
 */
export function withoutLeadingTitle(markdown, title) {
    const body = String(markdown ?? "");
    const heading = LEADING_HEADING.exec(body);

    if (!heading) return body;

    if (!same(heading[1], title)) return body;

    return body.slice(heading[0].length);
}

/** Une comparaison de lecteur : ni la casse ni les espaces ne comptent. */
function same(a, b) {
    return (
        "" !== String(b ?? "").trim() &&
        String(a).trim().toLocaleLowerCase() ===
            String(b).trim().toLocaleLowerCase()
    );
}
