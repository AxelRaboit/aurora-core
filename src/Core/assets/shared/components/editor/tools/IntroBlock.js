import { handlePlainTextPaste } from "./handlePlainTextPaste.js";

/**
 * A standfirst: the short paragraph that opens a page, set apart from the body.
 *
 * It exists because the meta description was doing this job by default — the
 * post card rendered it as the visible teaser — which conflated two different
 * texts. One is written for a search result and gets truncated around 160
 * characters; the other is written for a reader who already landed on the page.
 * Writing one and getting the other is how a 247-character "meta description"
 * ends up being prose.
 *
 * Deliberately a block rather than a field: it is optional, it lives where the
 * author puts it, and a page that does not want one simply has none.
 */
export default class IntroBlock {
    #element = null;
    #data;
    #placeholder;

    static get toolbox() {
        return {
            title: "Introduction",
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16"/><path d="M4 11h16"/><path d="M4 16h10"/></svg>',
        };
    }

    static get enableLineBreaks() {
        return true;
    }

    constructor({ data, config = {} }) {
        this.#data = { text: data.text ?? "" };
        this.#placeholder = config.placeholder ?? "Introduction…";
    }

    render() {
        this.#element = document.createElement("div");
        this.#element.contentEditable = "true";
        this.#element.className = "intro-block";
        this.#element.dataset.placeholder = this.#placeholder;
        this.#element.innerHTML = this.#data.text;
        this.#element.addEventListener("input", () => {
            this.#data.text = this.#element.innerHTML;
        });
        this.#element.addEventListener("paste", handlePlainTextPaste);

        return this.#element;
    }

    save() {
        return { text: this.#element?.innerHTML ?? this.#data.text };
    }

    validate(data) {
        return "" !== (data.text ?? "").trim();
    }
}
