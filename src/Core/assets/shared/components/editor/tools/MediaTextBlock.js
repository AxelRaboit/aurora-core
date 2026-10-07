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
        const flipButton = this.#createButton(
            this.#data.flip ? this.#config.flipRight : this.#config.flipLeft,
            () => {
                this.#data.flip = !this.#data.flip;
                this.#rebuild();
            },
        );
        toolbar.appendChild(flipButton);
        return toolbar;
    }

    #createRow() {
        const row = document.createElement("div");
        row.className = "mt-block__row";
        const imageColumn = this.#createImageCol();
        const textColumn = this.#createTextCol();
        row.appendChild(this.#data.flip ? textColumn : imageColumn);
        row.appendChild(this.#data.flip ? imageColumn : textColumn);
        return row;
    }

    #createImageCol() {
        const column = document.createElement("div");
        column.className = "mt-block__img-col";

        if (this.#data.url) {
            const image = document.createElement("img");
            image.src = this.#data.url;
            image.alt = this.#data.caption;
            image.className = "mt-block__img";

            const cap = document.createElement("p");
            cap.className = "mt-block__caption";
            cap.contentEditable = "true";
            cap.dataset.placeholder = this.#config.captionPlaceholder;
            cap.innerHTML = this.#data.caption;
            cap.addEventListener("input", () => {
                this.#data.caption = cap.innerHTML;
            });
            cap.addEventListener("paste", handlePlainTextPaste);

            column.appendChild(image);
            column.appendChild(cap);
            column.appendChild(
                this.#createButton(this.#config.changeUrl, () =>
                    this.#promptUrl(column),
                ),
            );
        } else {
            column.appendChild(
                this.#createUrlForm((url, mediaId = null) => {
                    this.#data.url = url;
                    this.#data.mediaId = mediaId;
                    this.#rebuild();
                }),
            );
        }

        return column;
    }

    #createTextCol() {
        const column = document.createElement("div");
        column.className = "mt-block__text-col";

        const editable = document.createElement("div");
        editable.contentEditable = "true";
        editable.className = "mt-block__text";
        editable.dataset.placeholder = this.#config.textPlaceholder;
        editable.innerHTML = this.#data.text;
        editable.addEventListener("input", () => {
            this.#data.text = editable.innerHTML;
        });
        editable.addEventListener("paste", handlePlainTextPaste);

        column.appendChild(editable);
        return column;
    }

    #promptUrl(column) {
        column.innerHTML = "";
        column.appendChild(
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

        const browseButton = this.#createButton(
            this.#config.browse,
            async () => {
                const pickedDocument = await openDocumentPicker({
                    imagesOnly: true,
                });
                const url = pickedDocument?.fileUrl ?? pickedDocument?.url;
                if (url) onConfirm(url, pickedDocument.id ?? null);
            },
        );
        browseButton.classList.add("mt-block__url-browse");

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
        // picker in the suite do - one endpoint, one category, one place
        // that knows the response shape.
        //
        // Replaces a "Media ID…" field that fetched /suite/media/media/{id}/info,
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

        const uploadButton = this.#createButton(this.#config.upload, () =>
            fileInput.click(),
        );
        uploadButton.classList.add("mt-block__url-upload");

        fileInput.addEventListener("change", async (event) => {
            const file = event.target.files?.[0];
            if (!file) return;

            error.style.display = "none";
            uploadButton.disabled = true;
            uploadButton.textContent = this.#config.uploading;

            const uploaded = await uploadImageFile(file);

            uploadButton.disabled = false;
            uploadButton.textContent = this.#config.upload;
            fileInput.value = "";

            if (!uploaded) {
                error.textContent = this.#config.uploadFailed;
                error.style.display = "block";

                return;
            }

            onConfirm(uploaded.url, uploaded.id);
        });

        wrapper.appendChild(browseButton);

        if (canUpload) {
            wrapper.appendChild(uploadButton);
            wrapper.appendChild(fileInput);
        }

        wrapper.appendChild(separator);
        wrapper.appendChild(urlInput);
        wrapper.appendChild(error);

        return wrapper;
    }

    #createButton(text, onClick) {
        const button = document.createElement("button");
        button.type = "button";
        button.className = "mt-block__btn";
        button.textContent = text;
        button.addEventListener("click", onClick);
        return button;
    }

    save() {
        return { ...this.#data };
    }

    validate(data) {
        return !!(data.url || data.text);
    }
}
