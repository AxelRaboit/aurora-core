import { handlePlainTextPaste } from "./handlePlainTextPaste.js";
import { openDocumentPicker } from "@shared/utils/documentPicker.js";
import { uploadImageFile } from "@shared/utils/http/uploadImageFile.js";
import { usePrivileges } from "@shared/composables/usePrivileges.js";

export default class MediaTextBlock {
    #wrapper = null;
    #data;
    #config;

    static get toolbox() {
        return {
            title: "Image + Text",
            icon: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="8" height="18" rx="1"/><rect x="13" y="3" width="8" height="18" rx="1"/></svg>',
        };
    }

    static get enableLineBreaks() {
        return true;
    }

    constructor({ data, config = {} }) {
        this.#data = {
            mediaId: data.mediaId ?? null,
            url: data.url ?? "",
            caption: data.caption ?? "",
            text: data.text ?? "",
            flip: data.flip ?? false,
        };
        this.#config = {
            flipLeft: config.flipLeft ?? "⇄ Image left",
            flipRight: config.flipRight ?? "⇄ Image right",
            captionPlaceholder: config.captionPlaceholder ?? "Caption…",
            textPlaceholder: config.textPlaceholder ?? "Your text…",
            urlPlaceholder: config.urlPlaceholder ?? "Image URL…",
            changeUrl: config.changeUrl ?? "Change URL",
            confirm: config.confirm ?? "Confirm",
            browse: config.browse ?? "Browse media",
            upload: config.upload ?? "Upload",
            uploading: config.uploading ?? "Uploading…",
            uploadFailed: config.uploadFailed ?? "The upload failed.",
            orLabel: config.orLabel ?? "or",
        };
    }

    render() {
        this.#wrapper = document.createElement("div");
        this.#wrapper.classList.add("media-text-block");
        this.#rebuild();
        return this.#wrapper;
    }

    #rebuild() {
        this.#wrapper.innerHTML = "";
        this.#wrapper.dataset.flip = this.#data.flip ? "1" : "0";
        this.#wrapper.appendChild(this.#createToolbar());
        this.#wrapper.appendChild(this.#createRow());
    }

    #createToolbar() {
        const toolbar = document.createElement("div");
        toolbar.className = "mt-block__toolbar";
        const flipBtn = this.#createButton(
            this.#data.flip ? this.#config.flipRight : this.#config.flipLeft,
            () => {
                this.#data.flip = !this.#data.flip;
                this.#rebuild();
            },
        );
        toolbar.appendChild(flipBtn);
        return toolbar;
    }

    #createRow() {
        const row = document.createElement("div");
        row.className = "mt-block__row";
        const imgCol = this.#createImageCol();
        const textCol = this.#createTextCol();
        row.appendChild(this.#data.flip ? textCol : imgCol);
        row.appendChild(this.#data.flip ? imgCol : textCol);
        return row;
    }

    #createImageCol() {
        const col = document.createElement("div");
        col.className = "mt-block__img-col";

        if (this.#data.url) {
            const img = document.createElement("img");
            img.src = this.#data.url;
            img.alt = this.#data.caption;
            img.className = "mt-block__img";

            const cap = document.createElement("p");
            cap.className = "mt-block__caption";
            cap.contentEditable = "true";
            cap.dataset.placeholder = this.#config.captionPlaceholder;
            cap.innerHTML = this.#data.caption;
            cap.addEventListener("input", () => {
                this.#data.caption = cap.innerHTML;
            });
            cap.addEventListener("paste", handlePlainTextPaste);

            col.appendChild(img);
            col.appendChild(cap);
            col.appendChild(
                this.#createButton(this.#config.changeUrl, () =>
                    this.#promptUrl(col),
                ),
            );
        } else {
            col.appendChild(
                this.#createUrlForm((url, mediaId = null) => {
                    this.#data.url = url;
                    this.#data.mediaId = mediaId;
                    this.#rebuild();
                }),
            );
        }

        return col;
    }

    #createTextCol() {
        const col = document.createElement("div");
        col.className = "mt-block__text-col";

        const editable = document.createElement("div");
        editable.contentEditable = "true";
        editable.className = "mt-block__text";
        editable.dataset.placeholder = this.#config.textPlaceholder;
        editable.innerHTML = this.#data.text;
        editable.addEventListener("input", () => {
            this.#data.text = editable.innerHTML;
        });
        editable.addEventListener("paste", handlePlainTextPaste);

        col.appendChild(editable);
        return col;
    }

    #promptUrl(col) {
        col.innerHTML = "";
        col.appendChild(
            this.#createUrlForm((url, mediaId = null) => {
                this.#data.url = url;
                this.#data.mediaId = mediaId;
                this.#rebuild();
            }),
        );
    }

    #createUrlForm(onConfirm) {
        const wrapper = document.createElement("div");
        wrapper.className = "mt-block__url-form";

        const browseBtn = this.#createButton(this.#config.browse, async () => {
            const doc = await openDocumentPicker({ imagesOnly: true });
            const url = doc?.fileUrl ?? doc?.url;
            if (url) onConfirm(url, doc.id ?? null);
        });
        browseBtn.classList.add("mt-block__url-browse");

        const separator = document.createElement("span");
        separator.className = "mt-block__url-or";
        separator.textContent = this.#config.orLabel;

        const urlInput = document.createElement("input");
        urlInput.type = "url";
        urlInput.placeholder = this.#config.urlPlaceholder;
        urlInput.className = "mt-block__url-input";
        const submitUrl = () => {
            const url = urlInput.value.trim();
            if (url) onConfirm(url);
        };
        urlInput.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                submitUrl();
            }
        });
        urlInput.addEventListener("blur", submitUrl);

        // Uploading from the machine, the same way the image block and every
        // picker in the backend do — one endpoint, one category, one place
        // that knows the response shape.
        //
        // Replaces a "Media ID…" field that fetched /backend/media/media/{id}/info,
        // a route removed with the Media module. It had been broken for as long
        // as that route had been gone, and browsing makes it redundant anyway.
        const error = document.createElement("p");
        error.className = "mt-block__url-error";
        error.style.display = "none";

        const fileInput = document.createElement("input");
        fileInput.type = "file";
        fileInput.accept = "image/*";
        fileInput.className = "mt-block__url-file";
        fileInput.hidden = true;

        // The endpoint is guarded by GED's permission, not the one that opened
        // the editor. Hiding the button beats a 403 with nothing to explain it.
        const canUpload = usePrivileges().can("ged.documents.create");

        const uploadBtn = this.#createButton(this.#config.upload, () =>
            fileInput.click(),
        );
        uploadBtn.classList.add("mt-block__url-upload");

        fileInput.addEventListener("change", async (event) => {
            const file = event.target.files?.[0];
            if (!file) return;

            error.style.display = "none";
            uploadBtn.disabled = true;
            uploadBtn.textContent = this.#config.uploading;

            const uploaded = await uploadImageFile(file);

            uploadBtn.disabled = false;
            uploadBtn.textContent = this.#config.upload;
            fileInput.value = "";

            if (!uploaded) {
                error.textContent = this.#config.uploadFailed;
                error.style.display = "block";

                return;
            }

            onConfirm(uploaded.url, uploaded.id);
        });

        wrapper.appendChild(browseBtn);

        if (canUpload) {
            wrapper.appendChild(uploadBtn);
            wrapper.appendChild(fileInput);
        }

        wrapper.appendChild(separator);
        wrapper.appendChild(urlInput);
        wrapper.appendChild(error);

        return wrapper;
    }

    #createButton(text, onClick) {
        const btn = document.createElement("button");
        btn.type = "button";
        btn.className = "mt-block__btn";
        btn.textContent = text;
        btn.addEventListener("click", onClick);
        return btn;
    }

    save() {
        return { ...this.#data };
    }

    validate(data) {
        return !!(data.url || data.text);
    }
}
