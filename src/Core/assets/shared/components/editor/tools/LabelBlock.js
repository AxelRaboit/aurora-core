import { handlePlainTextPaste } from "./handlePlainTextPaste.js";

/**
 * The colours a label can wear. Mirrors `BlocksRenderer::LABEL_TONES`: the
 * name becomes a class on the page, so only these ever reach it.
 */
export const LABEL_TONES = [
    "dark",
    "accent",
    "rose",
    "indigo",
    "lime",
    "amber",
    "sky",
    "emerald",
];

/**
 * A short word on a pill: « Réseaux sociaux » on black above a column, a
 * competitor's name on its colour. Optionally tilted, the hand-placed look
 * of a sticker on a slide.
 */
export default class LabelBlock {
    #wrapper = null;
    #textEl = null;
    #data;
    #toneLabels;
    #tiltLabel;
    #placeholder;

    static get toolbox() {
        return {
            title: "Label",
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/></svg>',
        };
    }

    constructor({ data, config = {} }) {
        this.#data = {
            text: data.text ?? "",
            tone: LABEL_TONES.includes(data.tone) ? data.tone : LABEL_TONES[0],
            tilt: true === data.tilt,
        };
        this.#toneLabels = config.toneLabels ?? {};
        this.#tiltLabel = config.tiltLabel ?? "Tilt";
        this.#placeholder = config.placeholder ?? "Label…";
    }

    render() {
        this.#wrapper = document.createElement("div");
        this.#rebuild();

        return this.#wrapper;
    }

    #rebuild() {
        this.#wrapper.innerHTML = "";
        this.#wrapper.className = "label-block";
        this.#wrapper.appendChild(this.#createSwatches());

        const row = document.createElement("p");
        row.className = "label-pill-row";
        this.#textEl = document.createElement("span");
        this.#textEl.contentEditable = "true";
        this.#textEl.className = `label-pill label-pill--${this.#data.tone}${this.#data.tilt ? " label-pill--tilt" : ""}`;
        this.#textEl.dataset.placeholder = this.#placeholder;
        this.#textEl.innerHTML = this.#data.text;
        this.#textEl.addEventListener("input", () => {
            this.#data.text = this.#textEl.innerHTML;
        });
        this.#textEl.addEventListener("paste", handlePlainTextPaste);
        row.appendChild(this.#textEl);
        this.#wrapper.appendChild(row);
    }

    #createSwatches() {
        const bar = document.createElement("div");
        bar.className = "label-block__tones";

        LABEL_TONES.forEach((tone) => {
            const btn = document.createElement("button");
            btn.type = "button";
            btn.className = `label-block__tone label-pill--${tone}${this.#data.tone === tone ? " label-block__tone--active" : ""}`;
            btn.title = this.#toneLabels[tone] ?? tone;
            btn.setAttribute("aria-label", btn.title);
            btn.setAttribute(
                "aria-pressed",
                this.#data.tone === tone ? "true" : "false",
            );
            btn.addEventListener("click", () => {
                this.#keepText();
                this.#data.tone = tone;
                this.#rebuild();
            });
            bar.appendChild(btn);
        });

        const tilt = document.createElement("button");
        tilt.type = "button";
        tilt.className = `label-block__tilt${this.#data.tilt ? " label-block__tilt--active" : ""}`;
        tilt.textContent = this.#tiltLabel;
        tilt.setAttribute("aria-pressed", this.#data.tilt ? "true" : "false");
        tilt.addEventListener("click", () => {
            this.#keepText();
            this.#data.tilt = !this.#data.tilt;
            this.#rebuild();
        });
        bar.appendChild(tilt);

        return bar;
    }

    #keepText() {
        if (this.#textEl) this.#data.text = this.#textEl.innerHTML;
    }

    save() {
        return {
            text: this.#textEl?.innerHTML ?? this.#data.text,
            tone: this.#data.tone,
            tilt: this.#data.tilt,
        };
    }

    validate(data) {
        return (
            "" !==
            String(data.text ?? "")
                .replace(/<[^>]*>/g, "")
                .trim()
        );
    }
}
