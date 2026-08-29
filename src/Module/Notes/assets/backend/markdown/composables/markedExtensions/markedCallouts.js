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
};

export function createCalloutExtension() {
    return {
        name: "callout",
        level: "block",
        start(src) {
            return src.match(/^>\s*\[!/)?.index;
        },
        tokenizer(src) {
            const match = src.match(
                /^(?:>\s*\[!(\w+)\]\s*(.*)\n)((?:>.*(?:\n|$))*)/,
            );
            if (!match) return undefined;

            const type = match[1].toLowerCase();
            const title = match[2].trim();
            const bodyRaw = match[3]
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
                title,
                tokens,
            };
        },
        renderer(token) {
            const def = CALLOUT_DEFINITIONS[token.calloutType] ?? {
                label: token.calloutType,
                icon: "info",
            };
            const title = token.title || def.label;
            const body = token.tokens?.length
                ? this.parser.parse(token.tokens)
                : "";

            return (
                `<div class="callout callout-${token.calloutType}" data-icon="${def.icon}">` +
                `<div class="callout-header"><span class="callout-title">${esc(title)}</span></div>` +
                (body ? `<div class="callout-body">${body}</div>` : "") +
                `</div>\n`
            );
        },
    };
}

function esc(str) {
    return String(str)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;");
}
