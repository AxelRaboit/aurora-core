const PALETTE = [
    { label: "Yellow", value: "#fef08a" },
    { label: "Lime", value: "#bef264" },
    { label: "Green", value: "#86efac" },
    { label: "Cyan", value: "#a5f3fc" },
    { label: "Sky", value: "#bae6fd" },
    { label: "Blue", value: "#bfdbfe" },
    { label: "Purple", value: "#ddd6fe" },
    { label: "Pink", value: "#fbcfe8" },
    { label: "Red", value: "#fecaca" },
    { label: "Orange", value: "#fed7aa" },
    { label: "Gray", value: "#e5e7eb" },
    { label: "Dark", value: "#1f2937" },
];

const TAG = "SPAN";
const CSS_CLASS = "cdx-text-bg";

const TOOLBAR_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m9 11-6 6v3h9l3-3"/><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4"/></svg>`;
const REMOVE_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>`;

const swatchIcon = (color) =>
    `<div style="width:18px;height:18px;border-radius:4px;background:${color};border:1.5px solid rgba(0,0,0,0.12);box-sizing:border-box;"></div>`;

export class BackgroundColorTool {
    static get isInline() {
        return true;
    }
    static get title() {
        return "Background Color";
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
            name: "text-bg",
            title: "Background Color",
            children: {
                items: [
                    ...PALETTE.map(({ label, value }) => ({
                        icon: swatchIcon(value),
                        title: label,
                        name: `text-bg-${label.toLowerCase().replace(/\s+/g, "-")}`,
                        closeOnActivate: true,
                        onActivate: () => this._applyColor(value),
                    })),
                    {
                        icon: REMOVE_ICON,
                        title: "Remove background",
                        name: "text-bg-remove",
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
            existing.style.backgroundColor = color;
            return;
        }

        const span = document.createElement(TAG);
        span.classList.add(CSS_CLASS);
        span.style.backgroundColor = color;

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
