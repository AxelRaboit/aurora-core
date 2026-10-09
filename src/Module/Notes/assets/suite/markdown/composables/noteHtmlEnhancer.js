/**
 * Finishes a rendered note once its HTML is on the page (09/10/2026).
 *
 * The Markdown renderer is synchronous and knows nothing of the page: it
 * leaves marked elements where a note needs more than HTML - a formula, a
 * diagram, an emoji written `:name:`, the table of contents, a note included
 * in this one, a code block to copy. This module finishes them, the same way
 * in the suite's preview, on a share page and in the reader, so the three
 * cannot drift.
 *
 * **Libraries on demand, drawings from a cache.** KaTeX, Mermaid and the emoji
 * data are loaded the first time a note needs them and never otherwise. Once
 * loaded, everything here runs before the browser paints: the preview is
 * re-rendered on every keystroke, and a formula or a diagram that blinked
 * while somebody typed elsewhere in the note would be the flicker people
 * notice first. A diagram already drawn for the same source and theme comes
 * from the cache rather than from Mermaid.
 */
import { loadEmojiData } from "./noteEmoji.js";

let katexModule = null;
let katexLoading = null;
let mermaidModule = null;
let mermaidLoading = null;
let mermaidTheme = null;
let mermaidCounter = 0;
const mermaidDrawings = new Map();

function loadKatex() {
    if (katexModule) return Promise.resolve(katexModule);
    katexLoading ??= Promise.all([
        import("katex"),
        import("katex/dist/katex.min.css"),
    ]).then(([module]) => {
        katexModule = module.default ?? module;

        return katexModule;
    });

    return katexLoading;
}

function loadMermaid() {
    if (mermaidModule) return Promise.resolve(mermaidModule);
    mermaidLoading ??= import("mermaid").then((module) => {
        mermaidModule = module.default ?? module;

        return mermaidModule;
    });

    return mermaidLoading;
}

function isDark() {
    return (
        "undefined" !== typeof document &&
        document.documentElement.classList.contains("dark")
    );
}

/**
 * @param {HTMLElement|null} root   the element holding the rendered note
 * @param {object} [options]
 * @param {string} [options.locale]           fr, en or es, for the emoji names
 * @param {object} [options.labels]           `{copy, copied, tableOfContents}`, translated
 * @param {Function} [options.loadEmbed]      `({title, heading}) => Promise<string|null>`, the HTML of an included note
 * @returns {Promise<void>}
 */
export async function enhanceNoteHtml(root, options = {}) {
    if (!root) return;
    const labels = options.labels ?? {};

    addCopyButtons(root, labels);
    fillTablesOfContents(root, labels);

    await Promise.all([
        renderFormulas(root),
        drawDiagrams(root),
        replaceEmojiShortcodes(root, options.locale ?? "fr"),
        includeNotes(root, options),
    ]);
}

function addCopyButtons(root, labels) {
    for (const block of root.querySelectorAll(".code-block")) {
        if (block.querySelector("[data-copy-code]")) continue;

        const button = document.createElement("button");
        button.type = "button";
        button.className = "code-block-copy";
        button.dataset.copyCode = "1";
        button.textContent = labels.copy ?? "Copy";
        button.addEventListener("click", async (event) => {
            event.preventDefault();
            event.stopPropagation();
            const code = block.querySelector("code")?.textContent ?? "";
            try {
                await navigator.clipboard.writeText(code);
                button.textContent = labels.copied ?? "Copied";
                setTimeout(() => {
                    button.textContent = labels.copy ?? "Copy";
                }, 1500);
            } catch {
                // Clipboard refused (an insecure page, a denied permission):
                // the code is still there to select by hand.
            }
        });
        block.append(button);
    }
}

/**
 * The headings a table of contents lists: the note's own, not the footnotes'
 * label nor those of a note included in it, as Notion does.
 */
function headingsOf(root) {
    return [...root.querySelectorAll("h1, h2, h3")].filter(
        (heading) =>
            !heading.closest(".footnotes, .md-toc, .md-embed") &&
            "" !== heading.textContent.trim(),
    );
}

function fillTablesOfContents(root, labels) {
    const tables = root.querySelectorAll("nav[data-toc]");
    if (0 === tables.length) return;

    const headings = headingsOf(root);
    const top = Math.min(
        ...headings.map((heading) => Number(heading.tagName.slice(1))),
        6,
    );

    for (const nav of tables) {
        if (nav.dataset.tocFilled) continue;
        nav.dataset.tocFilled = "1";
        nav.replaceChildren();

        const title = document.createElement("p");
        title.className = "md-toc-title";
        title.textContent = labels.tableOfContents ?? "Contents";
        nav.append(title);

        const list = document.createElement("ul");
        for (const heading of headings) {
            const item = document.createElement("li");
            item.style.paddingLeft = `${(Number(heading.tagName.slice(1)) - top) * 0.9}rem`;
            const link = document.createElement("a");
            link.href = "#";
            link.className = "md-toc-link";
            link.textContent = heading.textContent.trim();
            link.addEventListener("click", (event) => {
                event.preventDefault();
                event.stopPropagation();
                heading.scrollIntoView({ behavior: "smooth", block: "start" });
            });
            item.append(link);
            list.append(item);
        }
        nav.append(list);
    }
}

async function renderFormulas(root) {
    const formulas = [
        ...root.querySelectorAll(".md-math[data-math]:not([data-rendered])"),
    ];
    if (0 === formulas.length) return;

    let katex;
    try {
        katex = await loadKatex();
    } catch {
        return;
    }

    for (const element of formulas) {
        element.dataset.rendered = "1";
        try {
            katex.render(element.dataset.math ?? "", element, {
                displayMode: "1" === element.dataset.display,
                throwOnError: false,
                // No `\href`, `\url` or HTML extensions: a formula draws, it
                // does not link anywhere.
                trust: false,
            });
        } catch {
            // Left as written.
        }
    }
}

async function drawDiagrams(root) {
    const diagrams = [
        ...root.querySelectorAll(".md-mermaid:not([data-rendered])"),
    ];
    if (0 === diagrams.length) return;

    let mermaid;
    try {
        mermaid = await loadMermaid();
    } catch {
        return;
    }

    const theme = isDark() ? "dark" : "default";
    if (mermaidTheme !== theme) {
        mermaid.initialize({
            startOnLoad: false,
            securityLevel: "strict",
            theme,
        });
        mermaidTheme = theme;
    }

    for (const element of diagrams) {
        element.dataset.rendered = "1";
        const source = element.querySelector("code")?.textContent ?? "";
        const key = `${theme}\n${source}`;
        let svg = mermaidDrawings.get(key);
        if (undefined === svg) {
            try {
                mermaidCounter += 1;
                ({ svg } = await mermaid.render(
                    `note-mermaid-${mermaidCounter}`,
                    source,
                ));
                mermaidDrawings.set(key, svg);
            } catch {
                element.classList.add("md-mermaid-error");
                continue;
            }
        }
        element.innerHTML = svg;
    }
}

const SHORTCODE = /:([a-z0-9_+-]{2,40}):/gi;

async function replaceEmojiShortcodes(root, locale) {
    if (!SHORTCODE.test(root.textContent ?? "")) return;
    SHORTCODE.lastIndex = 0;

    let data;
    try {
        data = await loadEmojiData(locale);
    } catch {
        return;
    }

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
            // Code is written as it is meant: `:smile:` in a snippet stays.
            if (node.parentElement?.closest("code, pre, kbd, .md-math"))
                return NodeFilter.FILTER_REJECT;

            return node.nodeValue.includes(":")
                ? NodeFilter.FILTER_ACCEPT
                : NodeFilter.FILTER_REJECT;
        },
    });

    const nodes = [];
    while (walker.nextNode()) nodes.push(walker.currentNode);

    for (const node of nodes) {
        const replaced = node.nodeValue.replace(
            SHORTCODE,
            (whole, name) => data.byShortcode.get(name.toLowerCase()) ?? whole,
        );
        if (replaced !== node.nodeValue) node.nodeValue = replaced;
    }
}

async function includeNotes(root, options) {
    if ("function" !== typeof options.loadEmbed) return;

    const embeds = [
        ...root.querySelectorAll(
            ".md-embed[data-embed-title]:not([data-rendered])",
        ),
    ];
    await Promise.all(
        embeds.map(async (element) => {
            element.dataset.rendered = "1";
            // Inside an included note, an inclusion stays a link: a note that
            // includes itself, or two that include each other, would never end.
            if (element.parentElement?.closest(".md-embed")) return;

            let html = null;
            try {
                html = await options.loadEmbed({
                    title: element.dataset.embedTitle ?? "",
                    heading: element.dataset.heading ?? "",
                });
            } catch {
                html = null;
            }
            if (null === html || undefined === html) return;

            const body = document.createElement("div");
            body.className = "md-embed-body";
            body.innerHTML = html;
            element.append(body);
            element.classList.add("md-embed-loaded");
            await Promise.all([
                renderFormulas(body),
                drawDiagrams(body),
                replaceEmojiShortcodes(body, options.locale ?? "fr"),
            ]);
        }),
    );
}

/**
 * The part of a note an inclusion asks for: the whole text, the section under
 * a heading (down to the next heading of the same level or higher), or the
 * paragraph that ends with ` ^id`.
 */
export function markdownSection(markdown, heading = "") {
    const text = String(markdown ?? "");
    const wanted = String(heading ?? "").trim();
    if ("" === wanted) return text;

    const lines = text.split("\n");

    if (wanted.startsWith("^")) {
        const id = wanted.slice(1);
        const line = lines.find((one) =>
            new RegExp(
                `[ \\t]\\^${id.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\s*$`,
            ).test(one),
        );

        return line ?? "";
    }

    const start = lines.findIndex((line) => {
        const match = /^(#{1,6})\s+(.*?)\s*#*\s*$/.exec(line);

        return match && match[2].toLowerCase() === wanted.toLowerCase();
    });
    if (-1 === start) return "";

    const level = /^(#{1,6})/.exec(lines[start])[1].length;
    let end = lines.length;
    for (let index = start + 1; index < lines.length; index += 1) {
        const match = /^(#{1,6})\s/.exec(lines[index]);
        if (match && match[1].length <= level) {
            end = index;
            break;
        }
    }

    return lines.slice(start, end).join("\n");
}
