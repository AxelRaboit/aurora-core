/**
 * Ce qu'un glisser transporte, dans le carnet.
 *
 * Un seul type MIME et un seul format, `kind:id`, parce que deux endroits
 * l'écrivent et un seul le lit : les cartes de la bibliothèque, les lignes du
 * panneau du menu, et le dépôt qui range. Le panneau l'avait oublié un temps
 * et ses lignes se laissaient saisir sans rien transporter : le dépôt
 * recevait une chaîne vide et ne faisait rien, sans le dire.
 *
 * **Deux types de plus, qui ne portent rien.** Pendant le survol, le
 * navigateur ne laisse lire que la liste des types, jamais leur contenu : sans
 * eux, la cible ne sait pas si ce qu'on tient est un dossier qu'on essaie de
 * ranger dans son propre enfant, et elle s'allumait pour un dépôt que le
 * serveur allait refuser. Les types sont mis en minuscules par le navigateur,
 * d'où des noms qui le sont déjà.
 */
export const NOTE_DRAG_MIME = "application/x-aurora-note-item";

const KIND_PREFIX = "application/x-aurora-note-kind-";
const ID_PREFIX = "application/x-aurora-note-id-";

/** Remplit le presse-papier d'un glisser avec ce qui est saisi. */
export function startNoteDrag(event, kind, id) {
    if (!event.dataTransfer) return;

    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData(NOTE_DRAG_MIME, `${kind}:${id}`);
    event.dataTransfer.setData(`${KIND_PREFIX}${kind}`, "");
    event.dataTransfer.setData(`${ID_PREFIX}${id}`, "");
}

/** Ce que porte un glisser, ou null quand ce n'en est pas un des nôtres. */
export function readNoteDrag(event) {
    const raw = String(event.dataTransfer?.getData(NOTE_DRAG_MIME) ?? "");
    const [kind, id] = raw.split(":");

    if (!id || !["note", "folder"].includes(kind)) return null;

    return { kind, id: Number(id) };
}

/**
 * Ce que porte un glisser, lu pendant le survol.
 *
 * Même réponse que `readNoteDrag`, mais tirée des types, seuls lisibles avant
 * le dépôt. Null quand le glisser n'est pas des nôtres - un fichier, un
 * texte - pour que la cible reste éteinte.
 */
export function peekNoteDrag(event) {
    const types = Array.from(event.dataTransfer?.types ?? []);

    if (!types.includes(NOTE_DRAG_MIME)) return null;

    const kind = types
        .find((one) => one.startsWith(KIND_PREFIX))
        ?.slice(KIND_PREFIX.length);
    const id = types
        .find((one) => one.startsWith(ID_PREFIX))
        ?.slice(ID_PREFIX.length);

    if (!id || !["note", "folder"].includes(kind)) return null;

    return { kind, id: Number(id) };
}
