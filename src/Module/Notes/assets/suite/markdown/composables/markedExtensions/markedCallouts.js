/**
 * Marked block extension for Obsidian-style callouts.
 *
 * Syntax:
 *   > [!type] Optional title
 *   > Body line 1
 *   > Body line 2
 *
 * Renders to `<div class="callout callout-{type}">` with header + body.
 * Icons are picked via a `data-icon` attribute consumed by CSS.
 *
 * **Foldable, as in Obsidian (09/10/2026).** `> [!info]-` starts folded and
 * `> [!info]+` open; either renders as `<details>`, which folds without a line
 * of script on every page that shows a note. `> [!toggle]- Title` is the plain
 * fold of Notion, without the coloured box.
 */
const CALLOUT_DEFINITIONS = {
    note: { label: "Note", icon: "pencil" },
    tip: { label: "Tip", icon: "flame" },
    hint: { label: "Hint", icon: "flame" },
    info: { label: "Info", icon: "info" },
    warning: { label: "Warning", icon: "alert-triangle" },
    caution: { label: "Caution", icon: "alert-triangle" },
    danger: { label: "Danger", icon: "zap" },
    bug: { label: "Bug", icon: "bug" },
    example: { label: "Example", icon: "list" },
    quote: { label: "Quote", icon: "quote" },
    success: { label: "Success", icon: "check" },
    question: { label: "Question", icon: "help-circle" },
    faq: { label: "FAQ", icon: "help-circle" },
    abstract: { label: "Abstract", icon: "clipboard-list" },
    summary: { label: "Summary", icon: "clipboard-list" },
    todo: { label: "Todo", icon: "check-circle" },
    failure: { label: "Failure", icon: "x" },
    toggle: { label: "Details", icon: "chevron-right" },
};

export function createCalloutExtension() {
    return {
        name: "callout",
        level: "block",
        start(source) {
            return source.match(/^>\s*\[!/)?.index;
        },
        tokenizer(source) {
            const match = source.match(
                /^(?:>\s*\[!(\w+)\]([+-]?)[ \t]*(.*)(?:\n|$))((?:>.*(?:\n|$))*)/,
            );
            if (!match) return undefined;

            const type = match[1].toLowerCase();
            const fold = match[2];
            const title = match[3].trim();
            const bodyRaw = match[4]
                .split("\n")
                .map((line) => line.replace(/^>\s?/, ""))
                .join("\n")
                .trim();

            const tokens = [];
            if (bodyRaw) {
                this.lexer.blockTokens(bodyRaw, tokens);
            }

            return {
                type: "callout",
                raw: match[0],
                calloutType: type,
                // "" when the callout does not fold, "-" folded, "+" open.
                fold,
                title,
                tokens,
            };
        },
        renderer(token) {
            const definition = CALLOUT_DEFINITIONS[token.calloutType] ?? {
                label: token.calloutType,
                icon: "info",
            };
            const title = token.title || definition.label;
            const body = token.tokens?.length
                ? this.parser.parse(token.tokens)
                : "";

            if ("" !== token.fold || "toggle" === token.calloutType) {
                const open = "+" === token.fold ? " open" : "";

                return (
                    `<details class="callout callout-${token.calloutType} callout-foldable" data-icon="${definition.icon}"${open}>` +
                    `<summary class="callout-header"><span class="callout-title">${esc(title)}</span></summary>` +
                    (body ? `<div class="callout-body">${body}</div>` : "") +
                    `</details>\n`
                );
            }

            return (
                `<div class="callout callout-${token.calloutType}" data-icon="${definition.icon}">` +
                `<div class="callout-header"><span class="callout-title">${esc(title)}</span></div>` +
                (body ? `<div class="callout-body">${body}</div>` : "") +
                `</div>\n`
            );
        },
    };
}

function esc(text) {
    return String(text)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}
