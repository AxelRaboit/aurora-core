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
