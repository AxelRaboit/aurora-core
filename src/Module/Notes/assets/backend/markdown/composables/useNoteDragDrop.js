import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";

const DATA_TYPE = "application/x-aurora-note";

/**
 * Native HTML5 drag-drop for the note tree.
 *
 * Inspired by useMediaDragDrop in the media admin: each note row is
 * natively draggable, and drop targets read the dragged id from
 * dataTransfer to decide what to do. Compared to vue-draggable-plus
 * (Sortable.js), native DnD gives us:
 *
 * - Whole-row drop targets (vs. tiny inter-item slots) - drop ON note A
 *   to make the dragged note a child of A.
 *  - Explicit "root drop zone" via dropping on the sidebar background.
 * - No reactive plumbing issues: we mutate nothing during drag and
 *   issue a single `move` call on drop, then refreshList. The server
 *   is the source of truth.
 *
 * Sibling-reorder via DnD is intentionally out of scope here (drop on a
 * row = become child of the target). Users who need to reorder siblings
 * can use the explicit position or create-at-parent path; a follow-up
 * could add half-row "insert above/below" affordances.
 */
export function useNoteDragDrop({ api, refreshList }) {
    const { t } = useI18n();

    const draggingId = ref(null);
    const dragOverId = ref(null); // note id currently being hovered as drop target
    const rootDragOver = ref(false); // root sidebar background is being hovered

    function onDragStart(note, event) {
        if (!event.dataTransfer) return;
        draggingId.value = note.id;
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData(DATA_TYPE, String(note.id));
    }

    function onDragEnd() {
        draggingId.value = null;
        dragOverId.value = null;
        rootDragOver.value = false;
    }

    function onDragOverNote(note, event) {
        if (!event.dataTransfer?.types.includes(DATA_TYPE)) return;
        // Prevent dropping onto the dragged note itself.
        if (note.id === draggingId.value) return;
        event.preventDefault();
        event.stopPropagation();
        event.dataTransfer.dropEffect = "move";
        dragOverId.value = note.id;
        rootDragOver.value = false;
    }

    function onDragLeaveNote(note, event) {
        // Only clear if the leave actually exits the row (relatedTarget is outside).
        // Without this guard, hovering child elements (icons, text) fires leave/enter and flickers.
        const related = event.relatedTarget;
        if (related && event.currentTarget.contains(related)) return;
        if (dragOverId.value === note.id) dragOverId.value = null;
    }

    function onDragOverRoot(event) {
        if (!event.dataTransfer?.types.includes(DATA_TYPE)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
        rootDragOver.value = true;
        dragOverId.value = null;
    }

    function onDragLeaveRoot(event) {
        const related = event.relatedTarget;
        if (related && event.currentTarget.contains(related)) return;
        rootDragOver.value = false;
    }

    async function onDropOnNote(targetNote, event) {
        event.preventDefault();
        event.stopPropagation();
        const draggedId = Number(event.dataTransfer.getData(DATA_TYPE));
        dragOverId.value = null;
        draggingId.value = null;
        if (!draggedId || draggedId === targetNote.id) return;

        const { ok, reported, payload } = await api.move(
            draggedId,
            targetNote.id,
        );
        if (!ok && !reported) {
            // A refused cycle is worth naming; a 5xx has already been reported.
            const msg =
                payload?.error === "cycle"
                    ? t("notes.markdown.errors.reorder_cycle")
                    : t("notes.markdown.errors.reorder_failed");
            toast.error(msg);
        }
        await refreshList();
    }

    async function onDropOnRoot(event) {
        event.preventDefault();
        const draggedId = Number(event.dataTransfer.getData(DATA_TYPE));
        rootDragOver.value = false;
        draggingId.value = null;
        if (!draggedId) return;

        const { ok, reported } = await api.move(draggedId, null);
        if (!ok && !reported) {
            toast.error(t("notes.markdown.errors.reorder_failed"));
        }
        await refreshList();
    }

    return {
        draggingId,
        dragOverId,
        rootDragOver,
        onDragStart,
        onDragEnd,
        onDragOverNote,
        onDragLeaveNote,
        onDragOverRoot,
        onDragLeaveRoot,
        onDropOnNote,
        onDropOnRoot,
    };
}
