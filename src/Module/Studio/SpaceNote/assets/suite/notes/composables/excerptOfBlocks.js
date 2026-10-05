/**
 * Le début d'une note, en texte.
 *
 * Un post-it montre ce qu'il y a dedans sans l'ouvrir, et ce qu'il y a dedans
 * est une structure de blocs. Le rendu complet n'a pas sa place dans une carte
 * de six lignes - ni ses images, ni ses tableaux - donc on en tire du texte.
 *
 * Les balises que l'éditeur pose dans un paragraphe (gras, couleur, lien) sont
 * retirées plutôt qu'affichées : une carte qui montrerait `<b>` apprendrait au
 * lecteur que la note est cassée, ce qu'elle n'est pas.
 *
 * @param {Array} blocks
 * @param {number} limit
 */
export function excerptOfBlocks(blocks, limit = 400) {
    if (!Array.isArray(blocks)) return "";

    const parts = [];

    for (const block of blocks) {
        const data = block?.data ?? {};

        if (typeof data.text === "string") {
            parts.push(data.text);
        } else if (Array.isArray(data.items)) {
            // Une liste : ses entrées sont des chaînes ou des objets selon la
            // version de l'outil, et les deux se lisent ici.
            for (const item of data.items) {
                parts.push(
                    typeof item === "string" ? item : (item?.content ?? ""),
                );
            }
        } else if (typeof data.caption === "string" && "" !== data.caption) {
            // Une image sans texte autour : sa légende est ce qu'elle a à dire.
            parts.push(data.caption);
        }

        if (parts.join(" ").length > limit) break;
    }

    const text = parts
        .join("\n")
        .replace(/<[^>]*>/g, " ")
        .replace(/&nbsp;/g, " ")
        .replace(/[ \t]+/g, " ")
        .trim();

    return text.length > limit ? `${text.slice(0, limit).trimEnd()}…` : text;
}
