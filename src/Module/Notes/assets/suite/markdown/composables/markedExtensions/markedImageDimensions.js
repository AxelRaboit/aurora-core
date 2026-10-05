/**
 * Marked renderer override that adds Obsidian-style image dimensions on
 * top of the standard `![alt](url)` syntax.
 *
 * Recognised forms (alt-text suffix):
 *   - `![Caption|320](url)`         → width = 320 (height auto, ratio kept)
 *   - `![Caption|320x180](url)`     → explicit width + height
 *   - `![Caption](url)`             → no resize, default rendering
 *
 * The rendered HTML is a `<span class="note-image-wrap">` containing the
 * `<img>` and a `.note-image-handle` corner grip. The wrapper carries
 * `data-md-image="1"`, the image carries `data-md-src` + `data-md-width`
 * - both consumed by the resize logic in `NotePreview.vue` to drive a
 * drag-to-resize gesture and locate the right `![…](src)` token in the
 * markdown source when the user lets go.
 */

const DIMENSION_PATTERN = /^(.*?)\|(\d+)(?:x(\d+))?$/;

function escapeHtml(value) {
    return String(value ?? "").replace(
        /[&<>"']/g,
        (char) =>
            ({
                "&": "&amp;",
                "<": "&lt;",
                ">": "&gt;",
                '"': "&quot;",
                "'": "&#39;",
            })[char],
    );
}

export function createImageDimensionsRenderer() {
    return {
        image({ href, title, text }) {
            const match = DIMENSION_PATTERN.exec(text ?? "");
            const alt = match ? match[1] : (text ?? "");
            const width = match ? Number.parseInt(match[2], 10) : null;
            const height =
                match && match[3] ? Number.parseInt(match[3], 10) : null;

            const altAttr = alt ? ` alt="${escapeHtml(alt)}"` : ' alt=""';
            const titleAttr = title ? ` title="${escapeHtml(title)}"` : "";

            // We set both the style (so the rendered preview reflects the
            // stored width before the natural-size load completes) and the
            // legacy width/height attributes (so DOMPurify keeps the value
            // even when style attributes are stripped in stricter configs).
            const styleAttr = width
                ? ` style="width: ${width}px${height ? `; height: ${height}px` : ""}; max-width: 100%; height: auto;"`
                : "";
            const widthAttr = width ? ` width="${width}"` : "";
            const heightAttr = height ? ` height="${height}"` : "";

            return (
                '<span class="note-image-wrap" data-md-image="1">' +
                `<img src="${escapeHtml(href)}"${altAttr}${titleAttr}${styleAttr}${widthAttr}${heightAttr}` +
                ` data-md-src="${escapeHtml(href)}" data-md-width="${width ?? ""}" />` +
                '<span class="note-image-handle" data-md-handle="1" aria-hidden="true"></span>' +
                "</span>"
            );
        },
    };
}

/**
 * Rewrite (or insert) the dimension suffix on the first `![…](src)`
 * occurrence pointing at the given URL inside a markdown blob.
 *
 *   updateImageDimensionInContent(content, '/img/a.png', 320)
 *     →  ![alt|320](/img/a.png)
 *
 * Idempotent: replacing an existing `|N` or `|NxM` suffix with the new
 * value, or appending `|N` when no suffix is present.
 */
export function updateImageDimensionInContent(content, src, width) {
    if (!content || !src || !Number.isFinite(width)) return content;
    const safeWidth = Math.max(1, Math.round(width));
    const escapedSrc = src.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
    const pattern = new RegExp(
        `!\\[([^\\]\\n]*?)\\]\\(${escapedSrc}(\\s+"[^"]*")?\\)`,
    );
    return content.replace(pattern, (match, altRaw, titleSuffix = "") => {
        const alt = altRaw.replace(/\|\d+(?:x\d+)?$/, "");
        return `![${alt}|${safeWidth}](${src}${titleSuffix})`;
    });
}
