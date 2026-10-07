import { ref } from "vue";

/**
 * Wraps the crop modal target + post-crop refresh hook so DocumentsApp.vue
 * doesn't carry the ref + callback inline. The crop POST itself is owned by
 * `ImageCropperModal` (shared component) via `props.cropPath` - this composable
 * only manages the modal target and patches the detail view in place when the
 * cropped document is the one currently being inspected.
 */
export function useDocumentCrop(viewingDocument, reload) {
    const cropTarget = ref(null);

    function onCropped(updatedDocument) {
        if (!updatedDocument) return;
        if (viewingDocument.value?.id === updatedDocument.id)
            viewingDocument.value = updatedDocument;
        reload?.();
    }

    return { cropTarget, onCropped };
}
