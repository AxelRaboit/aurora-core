/**
 * Elides "de" before a vowel in a French string: "de Atelier" becomes
 * "d'Atelier".
 *
 * The catalogue writes "Livrables de {space}" once, and the name only
 * arrives at render time. No static text of the catalogue has "de" before a
 * vowel, so the rule only ever touches what was inserted.
 *
 * Left alone: a single letter ("de A à Z"), and y and h, which elide or not
 * depending on the word ("de Yann", "d'Hélène").
 *
 * The same rule lives in FrenchElision.php for the Twig half.
 */
const PATTERN =
    /(^|[\s(«"'])([Dd])e (?=[aeiouàâäéèêëîïôöûüAEIOUÀÂÄÉÈÊËÎÏÔÖÛÜ]\p{L})/gu;

export function frenchElision(text) {
    return typeof text === "string" ? text.replace(PATTERN, "$1$2'") : text;
}
