const SIZES = [
    { label: "XS", value: "0.75em" },
    { label: "S", value: "0.875em" },
    { label: "M", value: "1em" },
    { label: "L", value: "1.25em" },
    { label: "XL", value: "1.5em" },
    { label: "2XL", value: "2em" },
];

const TAG = "SPAN";
const CSS_CLASS = "cdx-font-size";

const TOOLBAR_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" x2="15" y1="20" y2="20"/><line x1="12" x2="12" y1="4" y2="20"/></svg>`;
const REMOVE_ICON = `<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>`;

const sizeIcon = (label) =>
    `<div style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;font-size:11px;font-weight:600;font-variant-numeric:tabular-nums;">${label}</div>`;

export class FontSizeTool {
    static get isInline() {
        return true;
    }
    static get title() {
        return "Font Size";
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
            name: "font-size",
            title: "Font Size",
            children: {
                items: [
                    ...SIZES.map(({ label, value }) => ({
                        icon: sizeIcon(label),
                        title: label,
                        name: `font-size-${label.toLowerCase()}`,
                        closeOnActivate: true,
                        onActivate: () => this._applySize(value),
                    })),
                    {
                        icon: REMOVE_ICON,
                        title: "Reset size",
                        name: "font-size-reset",
                        closeOnActivate: true,
                        onActivate: () => this._removeSize(),
                    },
                ],
            },
        };
    }

    _applySize(size) {
        const existing = this.api.selection.findParentTag(TAG, CSS_CLASS);
        if (existing) this.api.selection.expandToTag(existing);

        const selection = window.getSelection();
        if (!selection || !selection.rangeCount || selection.isCollapsed)
            return;

        const range = selection.getRangeAt(0);

        if (existing) {
            existing.style.fontSize = size;
            return;
        }

        const span = document.createElement(TAG);
        span.classList.add(CSS_CLASS);
        span.style.fontSize = size;

        try {
            range.surroundContents(span);
        } catch {
            const fragment = range.extractContents();
            span.appendChild(fragment);
            range.insertNode(span);
        }

        this.api.selection.expandToTag(span);
    }

    _removeSize() {
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
