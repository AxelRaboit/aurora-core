/**
 * Ce qui change entre deux textes, ligne par ligne.
 *
 * Pour l'historique d'une note : une version se compare à l'état courant en
 * montrant les lignes retirées et ajoutées, comme un « git diff » sans ses
 * en-têtes. Une note est du texte, et c'est ce qu'on veut voir d'abord : ce
 * qui a bougé, pas deux colonnes à relire côte à côte.
 *
 * Plus longue sous-suite commune, en O(n × m). Au-delà de `MAX_CELLS`
 * (deux notes de deux mille lignes), le calcul s'arrête et rend `null` :
 * l'écran montre alors la version entière plutôt que de figer la page.
 */
const MAX_CELLS = 4_000_000;

/**
 * @param {string} before le texte de la version
 * @param {string} after  le texte courant
 * @returns {Array<{kind: "same"|"removed"|"added", text: string}>|null}
 */
export function lineDiff(before, after) {
    const a = String(before ?? "").split("\n");
    const b = String(after ?? "").split("\n");

    if (a.length * b.length > MAX_CELLS) return null;

    // lengths[i][j] : la plus longue suite commune de a[i..] et b[j..].
    const lengths = Array.from(
        { length: a.length + 1 },
        () => new Uint32Array(b.length + 1),
    );
    for (let i = a.length - 1; i >= 0; i -= 1) {
        for (let j = b.length - 1; j >= 0; j -= 1) {
            lengths[i][j] =
                a[i] === b[j]
                    ? lengths[i + 1][j + 1] + 1
                    : Math.max(lengths[i + 1][j], lengths[i][j + 1]);
        }
    }

    const lines = [];
    let i = 0;
    let j = 0;
    while (i < a.length && j < b.length) {
        if (a[i] === b[j]) {
            lines.push({ kind: "same", text: a[i] });
            i += 1;
            j += 1;
        } else if (lengths[i + 1][j] >= lengths[i][j + 1]) {
            lines.push({ kind: "removed", text: a[i] });
            i += 1;
        } else {
            lines.push({ kind: "added", text: b[j] });
            j += 1;
        }
    }
    while (i < a.length) lines.push({ kind: "removed", text: a[i++] });
    while (j < b.length) lines.push({ kind: "added", text: b[j++] });

    return lines;
}

/** Combien de lignes ajoutées et retirées. */
export function diffStats(lines) {
    return (lines ?? []).reduce(
        (stats, line) => ({
            added: stats.added + ("added" === line.kind ? 1 : 0),
            removed: stats.removed + ("removed" === line.kind ? 1 : 0),
        }),
        { added: 0, removed: 0 },
    );
}
