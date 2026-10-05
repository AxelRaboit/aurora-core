import hljs from "@/shared/utils/format/highlighter.js";

// La liste des langages a demenage dans le module partage le jour ou le
// front en a eu besoin lui aussi : deux copies, c'etait un langage ajoute
// pour un lecteur et manquant pour un redacteur.

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
        code({ text, lang }) {
            const language = lang && hljs.getLanguage(lang) ? lang : null;
            const highlighted = language
                ? hljs.highlight(text, { language }).value
                : escapeHtml(text);
            const langLabel = language || "";
            const langClass = language ? ` language-${language}` : "";

            return `<div class="code-block">${
                langLabel
                    ? `<div class="code-block-lang">${langLabel}</div>`
                    : ""
            }<pre><code class="hljs${langClass}">${highlighted}</code></pre></div>\n`;
        },
    };
}
