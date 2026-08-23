import { HttpMethod } from "@/shared/utils/http/httpMethod.js";

/**
 * Where an image picked inside an editing form is filed.
 *
 * One constant because three callers need to agree on it, and the last time
 * they did not the block editor spent months posting to
 * `/backend/media/media/upload` - a route removed with the Media module, which
 * no test noticed because nothing asserted the route existed.
 */
export const IMAGE_UPLOAD_ENDPOINT = "/backend/ged/documents/upload-image";

/**
 * Uploads one image and returns the filed document, or null.
 *
 * Plain function rather than a composable: Editor.js calls its uploader from
 * inside its own plugin config, long after setup has run, so anything needing
 * an injection context is unusable there. `useImageUpload` wraps this for the
 * Vue callers that do want loading state and a toast.
 *
 * Returns null for every failure - a refused file, a network error, a body
 * that is not the shape agreed. Callers decide what to say about it; there is
 * nothing useful to distinguish between "the server said no" and "the server
 * said something unexpected" at a picker's level.
 *
 * @returns {Promise<{id: number, url: string}|null>}
 */
export async function uploadImageFile(file, endpoint = IMAGE_UPLOAD_ENDPOINT) {
    const body = new FormData();
    // `file` is the field name the endpoint reads. It was `image` here once,
    // against an endpoint that has never called it that.
    body.append("file", file);

    try {
        const response = await fetch(endpoint, {
            method: HttpMethod.Post,
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            body,
        });

        if (!response.ok) {
            return null;
        }

        const data = await response.json();

        if (!data?.success || !data.document?.fileUrl) {
            return null;
        }

        return { id: data.document.id, url: data.document.fileUrl };
    } catch {
        return null;
    }
}
