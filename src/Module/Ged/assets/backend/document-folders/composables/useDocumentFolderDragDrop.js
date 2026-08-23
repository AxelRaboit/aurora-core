import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Handles drag-and-drop reordering and reparenting of folder nodes.
 *
 * Drop zones:
 *   - top 30% of a row → insert BEFORE that folder (reorder, same parent)
 *   - middle 40%       → move INTO that folder (reparent)
 *   - bottom 30%       → insert AFTER that folder (reorder, same parent)
 *
 * After each successful operation the server returns the full updated list
 * via the `onSuccess(folders)` callback.
 */
export function useDocumentFolderDragDrop(movePath, reorderPath, onSuccess) {
    const { t } = useI18n();
    const { request: doRequest } = useRequest();

    const draggingId = ref(null);
    const dropTarget = ref(null); // { id, zone: 'before'|'into'|'after' }

    function onDragStart(event, folder) {
        draggingId.value = folder.id;
        event.dataTransfer.effectAllowed = "move";
        event.dataTransfer.setData(
            "application/x-aurora-folder",
            String(folder.id),
        );
    }

    function getZone(event) {
        const rect = event.currentTarget.getBoundingClientRect();
        const y = event.clientY - rect.top;
        const ratio = y / rect.height;
        if (ratio < 0.4) return "before";
        if (ratio > 0.6) return "after";
        return "into";
    }

    function onDragOver(event, folder) {
        if (draggingId.value === null || draggingId.value === folder.id) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = "move";
        dropTarget.value = { id: folder.id, zone: getZone(event) };
    }

    function onDragLeave(event) {
        // Only clear if leaving the element entirely (not entering a child)
        if (!event.currentTarget.contains(event.relatedTarget)) {
            dropTarget.value = null;
        }
    }

    function onDragEnd() {
        draggingId.value = null;
        dropTarget.value = null;
    }

    async function onDrop(event, targetFolder, flatTree) {
        event.preventDefault();
        // Recalculate zone from the drop event position rather than relying on the
        // last dragover state which may have been cleared by a dragLeave race.
        const zone = getZone(event);
        dropTarget.value = null;

        const draggedId = draggingId.value;
        draggingId.value = null;

        if (!draggedId || draggedId === targetFolder.id) return;

        if (zone === "into") {
            await reparent(draggedId, targetFolder.id);
        } else {
            await reorderSiblings(draggedId, targetFolder, zone, flatTree);
        }
    }

    async function reparent(draggedId, newParentId) {
        const url = buildPath(movePath, { id: draggedId });
        const data = await doRequest(url, { parentId: newParentId });
        if (data?.success) onSuccess(data.folders ?? []);
    }

    async function reorderSiblings(draggedId, targetFolder, zone, flatTree) {
        // Build the new sibling order for the target's parent level
        const parentId = targetFolder.parentId ?? null;
        const siblings = flatTree
            .filter((node) => (node.parentId ?? null) === parentId)
            .map((node) => node.id);

        // Remove dragged from its current position and insert relative to target
        const filtered = siblings.filter((id) => id !== draggedId);
        const targetIdx = filtered.indexOf(targetFolder.id);
        if (targetIdx === -1) {
            // Dragged comes from a different level - reparent first
            await reparent(draggedId, parentId);
            return;
        }

        const insertIdx = zone === "before" ? targetIdx : targetIdx + 1;
        filtered.splice(insertIdx, 0, draggedId);

        const data = await doRequest(reorderPath, { ids: filtered });
        if (data?.success) onSuccess(data.folders ?? []);
    }

    return {
        draggingId,
        dropTarget,
        onDragStart,
        onDragOver,
        onDragLeave,
        onDragEnd,
        onDrop,
    };
}
