import hljs from "@/shared/utils/format/highlighter.js";

// The language list moved to the shared module the day the front end needed
// it too: two copies meant a language added for a reader and missing for a
// writer.

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}

/**
 * Marked renderer override producing `<pre><code class="hljs language-XYZ">`
 * with highlight.js-tokenized content. Unknown languages (or fenced
 * blocks without a language) fall back to escaped plain text. The
 * surrounding `.code-block` wrapper carries an optional language label
 * displayed top-right (styled in `notes/markdown/preview.css`).
 */
export function createHighlightRenderer() {
    return {
        code({ text, lang: requestedLanguage }) {
            // A diagram, written as text (09/10/2026): the source stays
            // readable until `noteHtmlEnhancer.js` has loaded Mermaid and
            // drawn it, and stays if the drawing fails. It travels as the
            // block's text, not in an attribute: DOMPurify drops any
            // attribute holding `-->`, which is every arrow of a diagram.
            if (
                "mermaid" ===
                String(requestedLanguage ?? "")
                    .trim()
                    .toLowerCase()
            ) {
                return `<div class="md-mermaid"><pre><code>${escapeHtml(text)}</code></pre></div>\n`;
            }

            const language =
                requestedLanguage && hljs.getLanguage(requestedLanguage)
                    ? requestedLanguage
                    : null;
            const highlighted = language
                ? hljs.highlight(text, { language }).value
                : escapeHtml(text);
            const languageLabel = language || "";
            const languageClass = language ? ` language-${language}` : "";

            return `<div class="code-block">${
                languageLabel
                    ? `<div class="code-block-lang">${languageLabel}</div>`
                    : ""
            }<pre><code class="hljs${languageClass}">${highlighted}</code></pre></div>\n`;
        },
    };
}
