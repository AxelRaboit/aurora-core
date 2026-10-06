const PALETTE = [
    // The theme's own accent rather than the hex it happens to resolve to
    // today. A title coloured "accent" follows the palette when the theme
    // changes, and follows the reader between the light and the dark one -
    // `--th-accent` is accent-500 on one and accent-400 on the other, which is
    // the difference between a heading that reads and a heading that glares.
    //
    // `--th-accent` and not `--color-accent`: the theme block is declared
    // inline, so Tailwind resolves `--color-*` at build time and never emits
    // it as a custom property. The `--th-*` layer is the one that exists at
    // runtime.
    { label: "Accent", value: "var(--th-accent)" },
    { label: "Black", value: "#000000" },
    { label: "Dark gray", value: "#374151" },
    { label: "Gray", value: "#6b7280" },
    { label: "Light gray", value: "#d1d5db" },
    { label: "White", value: "#ffffff" },
    { label: "Red", value: "#ef4444" },
    { label: "Orange", value: "#f97316" },
    { label: "Yellow", value: "#eab308" },
    { label: "Green", value: "#22c55e" },
    { label: "Blue", value: "#3b82f6" },
    { label: "Purple", value: "#8b5cf6" },
    { label: "Pink", value: "#ec4899" },
];

const TAG = "SPAN";
const CSS_CLASS = "cdx-text-color";

const TOOLBAR_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h16"/><path d="m6 16 6-12 6 12"/><path d="M8 12h8"/></svg>`;
const REMOVE_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>`;

const swatchIcon = (color) =>
    `<div style="width:18px;height:18px;border-radius:4px;background:${color};border:1.5px solid rgba(0,0,0,0.12);box-sizing:border-box;"></div>`;

export class TextColorTool {
    static get isInline() {
        return true;
    }
    static get title() {
        return "Text Color";
    }
    static get sanitize() {
        return { span: { class: CSS_CLASS, style: true } };
    }

    constructor({ api }) {
        this.api = api;
    }

    render() {
        return {
            icon: TOOLBAR_ICON,
            name: "text-color",
            title: "Text Color",
            children: {
                items: [
                    ...PALETTE.map(({ label, value }) => ({
                        icon: swatchIcon(value),
                        title: label,
                        name: `text-color-${label.toLowerCase().replace(/\s+/g, "-")}`,
                        closeOnActivate: true,
                        onActivate: () => this._applyColor(value),
                    })),
                    {
                        icon: REMOVE_ICON,
                        title: "Remove color",
                        name: "text-color-remove",
                        closeOnActivate: true,
                        onActivate: () => this._removeColor(),
                    },
                ],
            },
        };
    }

    _applyColor(color) {
        const existing = this.api.selection.findParentTag(TAG, CSS_CLASS);
        if (existing) this.api.selection.expandToTag(existing);

        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || selection.isCollapsed)
            return;

        const range = selection.getRangeAt(0);

        if (existing) {
            existing.style.color = color;
            return;
        }

        const span = document.createElement(TAG);
        span.classList.add(CSS_CLASS);
        span.style.color = color;

        try {
            range.surroundContents(span);
        } catch {
            const fragment = range.extractContents();
            span.appendChild(fragment);
            range.insertNode(span);
        }

        this.api.selection.expandToTag(span);
    }

    _removeColor() {
        const span = this.api.selection.findParentTag(TAG, CSS_CLASS);
        if (!span) return;

        this.api.selection.expandToTag(span);
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount) return;

        const range = selection.getRangeAt(0);
        const fragment = range.extractContents();
        span.replaceWith(fragment);
    }

    checkState() {
        return !!this.api.selection.findParentTag(TAG, CSS_CLASS);
    }
}
