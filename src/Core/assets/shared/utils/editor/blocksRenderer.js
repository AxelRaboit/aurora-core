/**
 * Renders Editor.js blocks array to an HTML string.
 * @param {Array} blocks
 * @returns {string}
 */
export function renderBlocks(blocks) {
    if (!Array.isArray(blocks) || blocks.length === 0) return "";
    return blocks.map(renderBlock).filter(Boolean).join("\n");
}

function esc(text) {
    return String(text ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function renderBlock(block) {
    switch (block.type) {
        case "paragraph":
            return `<p>${block.data?.text ?? ""}</p>`;

        case "header": {
            const level = Math.min(Math.max(block.data?.level ?? 2, 1), 6);
            return `<h${level}>${block.data?.text ?? ""}</h${level}>`;
        }

        case "list": {
            const style = block.data?.style;
            const rawItems = block.data?.items ?? [];
            if (style === "checklist") {
                const items = rawItems
                    .map((item) => {
                        const text =
                            typeof item === "string"
                                ? item
                                : (item?.content ?? "");
                        const checked =
                            item?.meta?.checked ?? item?.checked ?? false;
                        return `<li class="checklist-item${checked ? " checked" : ""}">
                    <span class="check">${checked ? "✓" : "○"}</span>
                    <span>${text}</span>
                </li>`;
                    })
                    .join("");
                return `<ul class="checklist">${items}</ul>`;
            }
            const tag = style === "ordered" ? "ol" : "ul";
            const items = rawItems
                .map(
                    (item) =>
                        `<li>${typeof item === "string" ? item : (item?.content ?? "")}</li>`,
                )
                .join("");
            return `<${tag}>${items}</${tag}>`;
        }

        case "quote":
            return [
                `<blockquote>`,
                `<p>${block.data?.text ?? ""}</p>`,
                block.data?.caption
                    ? `<footer>${esc(block.data.caption)}</footer>`
                    : "",
                `</blockquote>`,
            ]
                .filter(Boolean)
                .join("");

        case "callout": {
            const calloutType = block.data?.type ?? "info";
            return `<div class="callout callout--${esc(calloutType)}">${block.data?.title ? `<strong>${esc(block.data.title)}</strong>` : ""}<p>${esc(block.data?.message ?? "")}</p></div>`;
        }

        case "delimiter":
            return `<hr />`;

        case "code":
            return `<pre><code>${esc(block.data?.code ?? "")}</code></pre>`;

        case "image": {
            const url = block.data?.file?.url ?? "";
            const caption = block.data?.caption ?? "";
            return [
                `<figure>`,
                url ? `<img src="${esc(url)}" alt="${esc(caption)}" />` : "",
                caption ? `<figcaption>${esc(caption)}</figcaption>` : "",
                `</figure>`,
            ]
                .filter(Boolean)
                .join("");
        }

        case "embed": {
            const { service, embed, caption, width, height } = block.data ?? {};
            return [
                `<figure class="embed embed--${esc(service)}">`,
                embed
                    ? `<iframe src="${esc(embed)}" width="${width ?? 580}" height="${height ?? 320}" frameborder="0" allowfullscreen></iframe>`
                    : "",
                caption ? `<figcaption>${esc(caption)}</figcaption>` : "",
                `</figure>`,
            ]
                .filter(Boolean)
                .join("");
        }

        case "table": {
            const withHeadings = block.data?.withHeadings;
            const content = block.data?.content ?? [];
            if (!content.length) return "";
            const [firstRow, ...bodyRows] = content;
            const head = withHeadings
                ? `<thead><tr>${firstRow.map((cell) => `<th>${cell}</th>`).join("")}</tr></thead>`
                : "";
            const rows = (withHeadings ? bodyRows : content)
                .map(
                    (row) =>
                        `<tr>${row.map((cell) => `<td>${cell}</td>`).join("")}</tr>`,
                )
                .join("");
            return `<table>${head}<tbody>${rows}</tbody></table>`;
        }

        case "mediaText": {
            const { url, caption, text, flip } = block.data ?? {};
            const imgCol = url
                ? `<div class="mt-block__img-col"><img src="${esc(url)}" alt="${esc(caption ?? "")}" />${caption ? `<p class="mt-block__caption">${esc(caption)}</p>` : ""}</div>`
                : "";
            const textCol = `<div class="mt-block__text-col">${text ?? ""}</div>`;
            const cols = flip ? `${textCol}${imgCol}` : `${imgCol}${textCol}`;
            return `<div class="mt-block__row">${cols}</div>`;
        }

        case "twoColumn": {
            const { left, right } = block.data ?? {};
            return `<div class="two-col-block"><div class="two-col-block__col">${left ?? ""}</div><div class="two-col-block__col">${right ?? ""}</div></div>`;
        }

        default:
            return "";
    }
}
