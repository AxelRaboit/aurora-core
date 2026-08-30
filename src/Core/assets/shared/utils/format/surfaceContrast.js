/**
 * Miroir client du service PHP `SurfaceContrast`.
 *
 * La duplication est assumée et limitée : le rendu du frontend public est
 * décidé côté serveur, par le service PHP, qui reste la référence. Ce fichier
 * n'existe que pour l'aperçu en direct de l'écran de thème, où attendre un
 * aller-retour serveur à chaque mouvement du sélecteur de couleur rendrait le
 * retour visuel inutilisable.
 *
 * Toute correction du calcul doit être portée des deux côtés. Les deux jeux de
 * tests partagent volontairement les mêmes couleurs de référence, pour qu'une
 * divergence se voie.
 */

/** @param {string} hex @returns {[number, number, number]} */
function hexToRgb(hex) {
    let value = String(hex ?? "")
        .trim()
        .replace(/^#/, "");

    if (value.length === 3) {
        value = value[0] + value[0] + value[1] + value[1] + value[2] + value[2];
    }

    if (value.length !== 6 || !/^[0-9a-f]{6}$/i.test(value)) {
        // Repli sur le blanc, donc sur le jeu clair : l'apparence historique du
        // frontend, la moins surprenante devant une saisie incomplète.
        return [255, 255, 255];
    }

    return [
        Number.parseInt(value.slice(0, 2), 16),
        Number.parseInt(value.slice(2, 4), 16),
        Number.parseInt(value.slice(4, 6), 16),
    ];
}

function toLinear(channel) {
    return channel <= 0.04045
        ? channel / 12.92
        : ((channel + 0.055) / 1.055) ** 2.4;
}

/** Luminance relative WCAG : 0 pour le noir, 1 pour le blanc. */
function relativeLuminance(hex) {
    const [r, g, b] = hexToRgb(hex);

    return (
        0.2126 * toLinear(r / 255) +
        0.7152 * toLinear(g / 255) +
        0.0722 * toLinear(b / 255)
    );
}

/** Rapport de contraste WCAG entre deux couleurs, de 1 à 21. */
export function contrastRatio(hexA, hexB) {
    const a = relativeLuminance(hexA);
    const b = relativeLuminance(hexB);
    const [lighter, darker] = a > b ? [a, b] : [b, a];

    return (lighter + 0.05) / (darker + 0.05);
}

/** Ce fond appelle-t-il un texte clair ? */
export function needsLightText(hex) {
    return contrastRatio(hex, "#ffffff") > contrastRatio(hex, "#000000");
}

/** Le meilleur rapport atteignable sur ce fond, blanc ou noir confondus. */
export function bestContrastRatio(hex) {
    return Math.max(
        contrastRatio(hex, "#ffffff"),
        contrastRatio(hex, "#000000"),
    );
}

/**
 * Seuil AAA de WCAG pour du texte courant.
 *
 * C'est AAA et non AA parce qu'AA ne peut pas échouer : en retenant toujours le
 * meilleur du noir et du blanc, le rapport ne descend jamais sous 4,608:1, sur
 * le gris `#757575`. Signaler AA reviendrait à afficher un avertissement qui ne
 * s'allume jamais.
 */
export const AAA_NORMAL_TEXT = 7;

export function meetsAaa(hex) {
    return bestContrastRatio(hex) >= AAA_NORMAL_TEXT;
}
