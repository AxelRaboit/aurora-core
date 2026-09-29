export const DEFAULT_TYPES = [
    { value: "info", label: "Info" },
    { value: "success", label: "Success" },
    { value: "warning", label: "Warning" },
    { value: "danger", label: "Danger" },
    { value: "tip", label: "Tip" },
    { value: "note", label: "Note" },
    { value: "question", label: "Question" },
    { value: "important", label: "Important" },
    { value: "update", label: "Update" },
    { value: "rose", label: "Rose" },
    { value: "accent", label: "Accent" },
    { value: "lime", label: "Lime" },
    { value: "amber", label: "Amber" },
    { value: "fuchsia", label: "Fuchsia" },
];

import { handlePlainTextPaste } from "./handlePlainTextPaste.js";
import { CALLOUT_ICONS, calloutIconSvg } from "./calloutIcons.js";

export default class CalloutBlock {
    #wrapper = null;
    #titleEl = null;
    #messageEl = null;
    #data;
    #types;
    #titlePlaceholder;
    #messagePlaceholder;
    #iconLabels;
    #noIconLabel;

    static get toolbox() {
        return {
            title: "Callout",
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
        };
    }

    static get enableLineBreaks() {
        return true;
    }

    constructor({ data, config = {} }) {
        this.#data = {
            type: data.type ?? "info",
            title: data.title ?? "",
            message: data.message ?? "",
            // A name from CALLOUT_ICONS, or "" for none. Unknown names are
            // kept as they are: the page simply draws no icon for them.
            icon: typeof data.icon === "string" ? data.icon : "",
        };
        this.#types = config.types ?? DEFAULT_TYPES;
        this.#titlePlaceholder = config.titlePlaceholder ?? "Title…";
        this.#messagePlaceholder = config.messagePlaceholder ?? "Message…";
        this.#iconLabels = config.iconLabels ?? {};
        this.#noIconLabel = config.noIconLabel ?? "No icon";
    }

    render() {
        this.#wrapper = document.createElement("div");
        this.#rebuild();
        return this.#wrapper;
    }

    #rebuild() {
        this.#wrapper.innerHTML = "";
        this.#wrapper.className = `callout-block callout-block--${this.#data.type}`;
        this.#wrapper.appendChild(this.#createTabs());
        this.#wrapper.appendChild(this.#createIcons());
        this.#titleEl = this.#createEditable(
            "callout-block__title",
            this.#titlePlaceholder,
            "title",
        );
        this.#messageEl = this.#createEditable(
            "callout-block__message",
            this.#messagePlaceholder,
            "message",
        );
        this.#wrapper.appendChild(this.#titleEl);
        this.#wrapper.appendChild(this.#messageEl);
    }

    #createTabs() {
        const tabs = document.createElement("div");
        tabs.className = "callout-block__tabs";
        this.#types.forEach(({ value, label }) => {
            tabs.appendChild(this.#createTab(value, label));
        });
        return tabs;
    }

    #createIcons() {
        const row = document.createElement("div");
        row.className = "callout-block__icons";
        row.appendChild(this.#createIconButton("", this.#noIconLabel, ""));
        CALLOUT_ICONS.forEach(({ value }) => {
            row.appendChild(
                this.#createIconButton(
                    value,
                    this.#iconLabels[value] ?? value,
                    calloutIconSvg(value),
                ),
            );
        });
        return row;
    }

    #createIconButton(value, label, svg) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = `callout-block__icon${this.#data.icon === value ? " callout-block__icon--active" : ""}`;
        btn.title = label;
        btn.setAttribute("aria-label", label);
        btn.setAttribute(
            "aria-pressed",
            this.#data.icon === value ? "true" : "false",
        );
        btn.innerHTML = svg || '<span aria-hidden="true">&#8856;</span>';
        btn.addEventListener("click", () => {
            this.#data.icon = value;
            this.#rebuild();
        });
        return btn;
    }

    #createTab(value, label) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = `callout-block__tab callout-block__tab--${value}${this.#data.type === value ? " callout-block__tab--active" : ""}`;
        btn.title = label;
        btn.addEventListener("click", () => {
            this.#data.type = value;
            this.#rebuild();
        });
        return btn;
    }

    #createEditable(className, placeholder, dataKey) {
        const el = document.createElement("div");
        el.contentEditable = "true";
        el.className = className;
        el.dataset.placeholder = placeholder;
        el.innerHTML = this.#data[dataKey];
        el.addEventListener("input", () => {
            this.#data[dataKey] = el.innerHTML;
        });
        el.addEventListener("paste", handlePlainTextPaste);
        return el;
    }

    save() {
        return {
            type: this.#data.type,
            title: this.#titleEl?.innerHTML ?? this.#data.title,
            message: this.#messageEl?.innerHTML ?? this.#data.message,
            icon: this.#data.icon,
        };
    }

    validate(data) {
        return !!(data.title || data.message);
    }
}
