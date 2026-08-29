/**
 * Client-side image downsizing before upload.
 *
 * For PNG/JPEG/WebP inputs that exceed `maxEdge` on either dimension,
 * the image is drawn onto a canvas scaled to fit and re-encoded as
 * WebP at the configured quality. This keeps server storage modest
 * (typical screenshot 3 MB → ~300 KB) without round-tripping the
 * original through the network.
 *
 * GIFs are returned untouched - `canvas.toBlob()` would flatten them
 * to a single frame, losing animation. Files already under the size
 * cap are also returned as-is so we don't re-encode for nothing
 * (and pay the lossy WebP cost on already-tight assets).
 *
 * If anything fails (browser without WebP encoder, broken image,
 * blob conversion error), we fall back to the original file rather
 * than blocking the upload - resize is an optimisation, not a gate.
 */

import { MimeType } from "@core/utils/enums/media/mimeType.js";

const DEFAULT_MAX_EDGE = 2048;
const DEFAULT_QUALITY = 0.85;
// Animated formats are passed through untouched - re-encoding via
// canvas.toBlob() would flatten them to a single frame.
const SKIP_MIME = new Set([MimeType.Gif]);

export function useNoteImageResize({
    maxEdge = DEFAULT_MAX_EDGE,
    quality = DEFAULT_QUALITY,
} = {}) {
    /**
     * Returns a Promise<File> - either the resized WebP or the
     * original file when resizing isn't applicable / fails.
     */
    async function resize(file) {
        if (!file?.type?.startsWith("image/")) return file;
        if (SKIP_MIME.has(file.type)) return file;

        let bitmap;
        try {
            bitmap = await loadBitmap(file);
        } catch {
            return file;
        }

        const { width, height } = bitmap;
        const longest = Math.max(width, height);
        if (longest <= maxEdge) {
            bitmap.close?.();
            return file;
        }

        const scale = maxEdge / longest;
        const targetWidth = Math.round(width * scale);
        const targetHeight = Math.round(height * scale);

        const canvas =
            typeof OffscreenCanvas === "function"
                ? new OffscreenCanvas(targetWidth, targetHeight)
                : Object.assign(document.createElement("canvas"), {
                      width: targetWidth,
                      height: targetHeight,
                  });
        const ctx = canvas.getContext("2d");
        if (!ctx) {
            bitmap.close?.();
            return file;
        }
        ctx.drawImage(bitmap, 0, 0, targetWidth, targetHeight);
        bitmap.close?.();

        try {
            const blob = canvas.convertToBlob
                ? await canvas.convertToBlob({ type: MimeType.Webp, quality })
                : await new Promise((resolve, reject) =>
                      canvas.toBlob(
                          (result) =>
                              result
                                  ? resolve(result)
                                  : reject(new Error("toBlob returned null")),
                          MimeType.Webp,
                          quality,
                      ),
                  );
            const baseName = file.name.replace(/\.[^.]+$/, "");
            return new File([blob], `${baseName}.webp`, {
                type: MimeType.Webp,
            });
        } catch {
            return file;
        }
    }

    return { resize };
}

/**
 * Decode a File into an ImageBitmap (preferred - async, off-main-thread
 * when supported) or fall back to an HTMLImageElement when the browser
 * lacks `createImageBitmap`.
 */
async function loadBitmap(file) {
    if (typeof createImageBitmap === "function") {
        return createImageBitmap(file);
    }
    return new Promise((resolve, reject) => {
        const image = new Image();
        const url = URL.createObjectURL(file);
        image.onload = () => {
            URL.revokeObjectURL(url);
            resolve(image);
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error("Failed to decode image"));
        };
        image.src = url;
    });
}
