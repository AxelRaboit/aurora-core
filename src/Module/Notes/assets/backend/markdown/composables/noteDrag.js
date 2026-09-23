/**
 * Ce qu'un glisser transporte, dans le carnet.
 *
 * Un seul type MIME et un seul format, `kind:id`, parce que deux endroits
 * l'écrivent et un seul le lit : les cartes de la bibliothèque, les lignes du
 * panneau du menu, et le dépôt qui range. Le panneau l'avait oublié un temps
 * et ses lignes se laissaient saisir sans rien transporter : le dépôt
 * recevait une chaîne vide et ne faisait rien, sans le dire.
 */
export const NOTE_DRAG_MIME = "application/x-aurora-note-item";

/** Remplit le presse-papier d'un glisser avec ce qui est saisi. */
export function startNoteDrag(event, kind, id) {
    if (!event.dataTransfer) return;

    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData(NOTE_DRAG_MIME, `${kind}:${id}`);
}

/** Ce que porte un glisser, ou null quand ce n'en est pas un des nôtres. */
export function readNoteDrag(event) {
    const raw = String(event.dataTransfer?.getData(NOTE_DRAG_MIME) ?? "");
    const [kind, id] = raw.split(":");

    if (!id || !["note", "folder"].includes(kind)) return null;

    return { kind, id: Number(id) };
}
