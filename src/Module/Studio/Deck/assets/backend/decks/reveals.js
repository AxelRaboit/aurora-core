/**
 * Combien de lignes une slide peut faire entrer une a une.
 *
 * **Compte depuis le contenu, jamais depuis la page.** Les lignes vivent dans
 * un slot de liste, donc leur nombre est connu avant que quoi que ce soit soit
 * dessine ; demander au DOM lierait la facon dont un deck se pilote a la facon
 * dont il se trouve mis en page ce jour-la, et repondrait autrement sur une
 * vignette.
 *
 * **Dans son propre fichier parce que deux fenetres s'en servent.** Le lecteur
 * projette et le presentateur pilote, et les deux doivent compter pareil : une
 * copie dans chacun, c'est le jour ou l'une gagne un gabarit et ou les deux
 * fenetres cessent d'etre d'accord sur le nombre de pressions que la slide
 * demande.
 */
const REVEAL_SLOTS = ["bullets", "items", "steps", "figures", "lines"];

/** Zero pour toute slide qui n'a rien demande, donc pour toutes les anciennes. */
export function revealableIn(slide) {
    const content = slide?.content;

    if (content?.reveal !== true) return 0;

    const slot = REVEAL_SLOTS.find((name) => Array.isArray(content[name]));

    return slot ? content[slot].length : 0;
}
