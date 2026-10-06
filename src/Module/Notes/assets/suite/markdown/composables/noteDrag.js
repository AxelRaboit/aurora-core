/**
 * What a drag carries, in the notebook.
 *
 * A single MIME type and a single format, `kind:id`, because two places
 * write it and one reads it: the library cards, the menu panel rows, and the
 * drop that files. The panel had forgotten it for a while and its rows could
 * be grabbed without carrying anything: the drop received an empty string
 * and did nothing, without saying so.
 *
 * **Two more types, which carry nothing.** During the hover, the browser
 * only lets the list of types be read, never their content: without them,
 * the target does not know whether what is held is a folder one is trying to
 * file into its own child, and it lit up for a drop the server was going to
 * refuse. Types are lowercased by the browser, hence names that already are.
 */
export const NOTE_DRAG_MIME = "application/x-aurora-note-item";

const KIND_PREFIX = "application/x-aurora-note-kind-";
const ID_PREFIX = "application/x-aurora-note-id-";

/** Fills a drag's clipboard with what is grabbed. */
export function startNoteDrag(event, kind, id) {
    if (!event.dataTransfer) return;

    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData(NOTE_DRAG_MIME, `${kind}:${id}`);
    event.dataTransfer.setData(`${KIND_PREFIX}${kind}`, "");
    event.dataTransfer.setData(`${ID_PREFIX}${id}`, "");
}

/** What a drag carries, or null when it is not one of ours. */
export function readNoteDrag(event) {
    const raw = String(event.dataTransfer?.getData(NOTE_DRAG_MIME) ?? "");
    const [kind, id] = raw.split(":");

    if (!id || !["note", "folder"].includes(kind)) return null;

    return { kind, id: Number(id) };
}

/**
 * What a drag carries, read during the hover.
 *
 * Same answer as `readNoteDrag`, but taken from the types, the only thing
 * readable before the drop. Null when the drag is not one of ours - a file,
 * a text - so that the target stays off.
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
