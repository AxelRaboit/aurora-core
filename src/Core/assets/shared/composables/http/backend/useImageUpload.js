import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Upload an image file and persist it as a GED document, in one call from
 * the consumer's point of view.
 *
 * GED's own `/upload` endpoint is deliberately two-step (upload the bytes,
 * then a separate `/create` call persists the Document row — built for its
 * multi-field create form). This composable chains both calls so simple
 * "pick one image" UIs (OG image, featured image, custom fields) don't have
 * to know about that.
 *
 * Usage:
 *   const { uploading, inputRef, uploadFromEvent, reset } = useImageUpload({
 *       onSuccess: ({ file, media }) => { ... },
 *       onError: () => toast.error(...),
 *   });
 *
 * The returned `inputRef` should be bound to a hidden <input type="file"> so its
 * value can be cleared after upload (allows selecting the same file again).
 */
export function useImageUpload({
    onSuccess,
    onError,
    uploadEndpoint = "/backend/ged/documents/upload",
    createEndpoint = "/backend/ged/documents/create",
} = {}) {
    const { request } = useRequest();
    const uploading = ref(false);
    const inputRef = ref(null);

    async function uploadFromEvent(event) {
        const file = event.target.files?.[0];
        if (!file) return;
        uploading.value = true;
        try {
            const body = new FormData();
            body.append("file", file);
            const uploaded = await request(uploadEndpoint, null, {
                rawBody: body,
            });
            if (!uploaded?.success) {
                onError?.();
                return;
            }

            const created = await request(createEndpoint, {
                title: uploaded.originalName || file.name,
                filePath: uploaded.filePath,
                fileName: uploaded.fileName,
                originalName: uploaded.originalName,
                mimeType: uploaded.mimeType,
                size: uploaded.size,
                width: uploaded.width,
                height: uploaded.height,
                thumbnailPath: uploaded.thumbnailPath,
            });
            if (!created?.success) {
                onError?.();
                return;
            }

            const document = created.document;
            onSuccess?.({
                file: { id: document.id, url: document.fileUrl },
                media: { focalPositionCss: document.focalPositionCss },
            });
        } finally {
            uploading.value = false;
            if (inputRef.value?.reset) {
                inputRef.value.reset();
            } else if (inputRef.value) {
                inputRef.value.value = "";
            }
        }
    }

    return { uploading, inputRef, uploadFromEvent };
}
