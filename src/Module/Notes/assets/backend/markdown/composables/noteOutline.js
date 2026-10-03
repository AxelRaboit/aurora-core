/**
 * Le plan d'une note : ses titres, dans l'ordre, et sa longueur.
 *
 * Craft et Notion montrent les titres d'une page à côté d'elle, pour s'y
 * repérer et y sauter ; une note longue (un brief, une procédure) se lit mal
 * sans. Les titres se lisent dans le texte Markdown lui-même, sans passer par
 * le rendu : le plan sert aussi en mode écriture, où il n'y a pas d'aperçu.
 *
 * Ce qui est dans un bloc de code ne compte pas : un `# commentaire` de shell
 * n'est pas un titre, et ses mots ne sont pas ceux qu'on lit.
 */

/** Les mots qu'on lit en une minute, à l'écran. */
const WORDS_PER_MINUTE = 220;

const FENCE = /^\s*(```|~~~)/;
const HEADING = /^\s{0,3}(#{1,6})\s+(.+?)\s*#*\s*$/;

/** Un titre tel qu'on le lit : sans sa syntaxe de liens, d'emphase ou de code. */
function readable(text) {
    return text
        .replace(/\[\[([^\]|]+)\|([^\]]+)\]\]/g, "$2")
        .replace(/\[\[([^\]]+)\]\]/g, "$1")
        .replace(/!?\[([^\]]*)\]\([^)]*\)/g, "$1")
        .replace(/[*_~`]+/g, "")
        .trim();
}

/**
 * Les lignes de texte, hors blocs de code, avec leur numéro.
 *
 * @param {string} markdown
 * @returns {Array<{line: number, text: string}>}
 */
function proseLines(markdown) {
    const lines = String(markdown ?? "").split("\n");
    const prose = [];
    let inFence = false;

    lines.forEach((text, line) => {
        if (FENCE.test(text)) {
            inFence = !inFence;

            return;
        }

        if (!inFence) prose.push({ line, text });
    });

    return prose;
}

/**
 * Les titres de la note.
 *
 * @param {string} markdown
 * @returns {Array<{level: number, text: string, line: number}>}
 */
export function outlineOf(markdown) {
    return proseLines(markdown)
        .map(({ line, text }) => {
            const match = HEADING.exec(text);

            return match
                ? { level: match[1].length, text: readable(match[2]), line }
                : null;
        })
        .filter((heading) => heading && "" !== heading.text);
}

/**
 * Le nombre de mots qu'on lit : la prose, sans la syntaxe Markdown ni le
 * code.
 *
 * @param {string} markdown
 */
export function wordCount(markdown) {
    const text = proseLines(markdown)
        .map(({ text: one }) =>
            readable(
                one
                    .replace(/^\s*(#{1,6}|[-*+]|\d+[.)]|>)\s+/, "")
                    .replace(/^\s*\[[ xX]\]\s+/, ""),
            ),
        )
        .join(" ");

    return text.split(/\s+/).filter((word) => /[\p{L}\p{N}]/u.test(word))
        .length;
}

/** Le temps de lecture, en minutes entières, une au moins dès qu'il y a un mot. */
export function readingMinutes(words) {
    return words > 0 ? Math.max(1, Math.round(words / WORDS_PER_MINUTE)) : 0;
}
