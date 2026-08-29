import hljs from "highlight.js/lib/core";

// Common languages only - keeps the chunk under ~50kb gzip. Adding a
// language costs roughly 1-4kb gzip. The `xml` package covers HTML.
import javascript from "highlight.js/lib/languages/javascript";
import typescript from "highlight.js/lib/languages/typescript";
import php from "highlight.js/lib/languages/php";
import css from "highlight.js/lib/languages/css";
import xml from "highlight.js/lib/languages/xml";
import json from "highlight.js/lib/languages/json";
import bash from "highlight.js/lib/languages/bash";
import sql from "highlight.js/lib/languages/sql";
import python from "highlight.js/lib/languages/python";
import markdown from "highlight.js/lib/languages/markdown";
import yaml from "highlight.js/lib/languages/yaml";

hljs.registerLanguage("javascript", javascript);
hljs.registerLanguage("js", javascript);
hljs.registerLanguage("typescript", typescript);
hljs.registerLanguage("ts", typescript);
hljs.registerLanguage("php", php);
hljs.registerLanguage("css", css);
hljs.registerLanguage("html", xml);
hljs.registerLanguage("xml", xml);
hljs.registerLanguage("json", json);
hljs.registerLanguage("bash", bash);
hljs.registerLanguage("sh", bash);
hljs.registerLanguage("sql", sql);
hljs.registerLanguage("python", python);
hljs.registerLanguage("py", python);
hljs.registerLanguage("markdown", markdown);
hljs.registerLanguage("md", markdown);
hljs.registerLanguage("yaml", yaml);
hljs.registerLanguage("yml", yaml);

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
