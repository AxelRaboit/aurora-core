/**
 * How a family member is shown on a chip: a colour dot when its label names a
 * colour, its label as text otherwise.
 *
 * A family is not always about colour. `fr`, `en`, `téléphone` or `carré` are
 * as good labels as `jaune`, and guessing a hue from the picture would take
 * an English version for a colour. So only a label that *is* a colour name,
 * in French, English or Spanish, gets a dot.
 */
const SWATCHES = {
    vert: "#34d399",
    green: "#34d399",
    verde: "#34d399",
    jaune: "#e0a21a",
    yellow: "#e0a21a",
    amarillo: "#e0a21a",
    rouge: "#bd4a55",
    red: "#bd4a55",
    rojo: "#bd4a55",
    bleu: "#0093ed",
    blue: "#0093ed",
    azul: "#0093ed",
    violet: "#8b6cff",
    purple: "#8b6cff",
    morado: "#8b6cff",
    orange: "#f97316",
    naranja: "#f97316",
    rose: "#ec4899",
    pink: "#ec4899",
    rosa: "#ec4899",
    noir: "#111827",
    black: "#111827",
    negro: "#111827",
    blanc: "#f9fafb",
    white: "#f9fafb",
    blanco: "#f9fafb",
    gris: "#9ca3af",
    gray: "#9ca3af",
    grey: "#9ca3af",
};

/**
 * @param {string|null|undefined} label
 * @returns {string|null} a hex colour, or null when the label is not a colour
 */
export function labelSwatch(label) {
    if (!label) return null;

    const key = String(label)
        .trim()
        .toLowerCase()
        .normalize("NFD")
        .replace(/[̀-ͯ]/g, "");

    return SWATCHES[key] ?? null;
}

/**
 * The members of a row's family in chip order: the original first, then its
 * alternates as the server sorted them (by label).
 *
 * @param {object} doc a listing row carrying `alternates`
 * @returns {Array<{id: number, label: string|null, thumbnailUrl: string|null, usageCount: number, original: boolean}>}
 */
export function familyMembers(doc) {
    if (!doc?.alternates?.length) return [];

    return [
        {
            id: doc.id,
            label: null,
            title: doc.title,
            thumbnailUrl: doc.thumbnailUrl ?? null,
            usageCount: doc.usageCount ?? 0,
            original: true,
        },
        ...doc.alternates.map((member) => ({ ...member, original: false })),
    ];
}
