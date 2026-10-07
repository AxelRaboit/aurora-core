import { handlePlainTextPaste } from "./handlePlainTextPaste.js";

export default class TwoColumnBlock {
    #wrapper = null;
    #left = null;
    #right = null;
    #data;

    static get toolbox() {
        return {
            title: "Two Columns",
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="8" height="18" rx="1"/><rect x="13" y="3" width="8" height="18" rx="1"/></svg>',
        };
    }

    static get enableLineBreaks() {
        return true;
    }

    constructor({ data }) {
        this.#data = {
            left: data.left ?? "",
            right: data.right ?? "",
        };
    }

    render() {
        this.#wrapper = document.createElement("div");
        this.#wrapper.className = "two-col-block";
        this.#left = this.#makeCol(
            this.#data.left,
            "Colonne gauche…",
            (html) => {
                this.#data.left = html;
            },
        );
        this.#right = this.#makeCol(
            this.#data.right,
            "Colonne droite…",
            (html) => {
                this.#data.right = html;
            },
        );
        this.#wrapper.appendChild(this.#left);
        this.#wrapper.appendChild(this.#right);
        return this.#wrapper;
    }

    #makeCol(html, placeholder, onChange) {
        const column = document.createElement("div");
        column.className = "two-col-block__col";
        column.contentEditable = "true";
        column.dataset.placeholder = placeholder;
        column.innerHTML = html;
        column.addEventListener("input", () => onChange(column.innerHTML));
        column.addEventListener("paste", handlePlainTextPaste);
        return column;
    }

    save() {
        return {
            left: this.#left?.innerHTML ?? this.#data.left,
            right: this.#right?.innerHTML ?? this.#data.right,
        };
    }

    validate(data) {
        return !!(data.left || data.right);
    }
}
