import { ref } from "vue";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";

/**
 * Upload an image file and get back a filed, reusable GED document.
 *
 * One call, and deliberately so. GED's own `/upload` stops at the bytes and
 * leaves the Document row to its create form, which asks for a category, a
 * folder and tags — right for that screen, wrong for an author picking a
 * picture in the middle of writing a banner. Chaining the two calls from here
 * used to work but left the filing to the caller, so nothing was filed: the
 * result was a published-looking image that was in fact an uncategorised
 * draft, invisible in the picker the moment the page reloaded.
 *
 * `/upload-image` decides both server-side. Where the image lands is not the
 * browser's to choose.
 *
 * Usage:
 *   const { uploading, inputRef, uploadFromEvent } = useImageUpload({
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
    endpoint = "/backend/ged/documents/upload-image",
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

            const created = await request(endpoint, null, { rawBody: body });

            if (!created?.success) {
                onError?.(created?.message ?? null);
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
