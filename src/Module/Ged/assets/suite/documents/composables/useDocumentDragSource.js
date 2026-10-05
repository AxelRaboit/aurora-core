/**
 * What the documents listing puts on the clipboard when a card is dragged.
 *
 * Only the *source* half lives here now. The drop targets - the folder rows -
 * moved into the side menu's panel along with the rest of the folder aside, and
 * with them the two move endpoints. Keeping a second copy of that logic here,
 * against rows this page no longer draws, is how the two would drift.
 *
 * The MIME type is the contract between the two Vue applications, and it costs
 * nothing to cross: `dataTransfer` belongs to the browser, not to either app.
 */
const DOC_MIME = "application/x-aurora-document";

export function useDocumentDragSource() {
    function onDocumentDragStart(event, doc) {
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData(DOC_MIME, String(doc.id));
    }

    return { onDocumentDragStart };
}
