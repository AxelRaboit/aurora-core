import { createApp, h, ref } from "vue";
import { createAppI18n } from "@/i18n.js";
import DocumentPickerModal from "@ged/backend/documents/components/DocumentPickerModal.vue";

/**
 * Imperative wrapper around <DocumentPickerModal> — mirror of
 * `openMediaPicker()` so Phase 2 consumers (Erp Product, Ecommerce Listing,
 * branding, etc.) can swap in a single line as their backing FK migrates
 * from `core_media` to `core_ged_documents`.
 *
 * Resolves with the selected document (full serialized payload from
 * `/backend/ged/documents/list`) or `null` if the user cancels.
 *
 * `imagesOnly` filters the visible documents to `image/*` MIME types.
 * `mimeFilter` is the more granular knob (single MIME, e.g.
 * "application/pdf") and is forwarded to the modal directly.
 */
export function openDocumentPicker({
    imagesOnly = false,
    mimeFilter = null,
    multiple = false,
    listPath = "/backend/ged/documents/list",
} = {}) {
    return new Promise((resolve) => {
        const host = document.createElement("div");
        document.body.appendChild(host);

        const show = ref(true);
        let resolved = false;

        function finish(value) {
            if (resolved) return;
            resolved = true;
            show.value = false;
            setTimeout(() => {
                // `finish` is passed into the app it tears down, so it is
                // written before `app` exists. Read inside a timeout, long
                // after mounting.
                // eslint-disable-next-line no-use-before-define
                app.unmount();
                host.remove();
            }, 250);
            resolve(value);
        }

        const locale = document.documentElement.lang?.slice(0, 2) || "fr";

        const app = createApp({
            render() {
                return h(DocumentPickerModal, {
                    show: show.value,
                    listPath,
                    mimeFilter,
                    mimePrefix: imagesOnly ? "image/" : null,
                    multiple,
                    onClose: () => finish(multiple ? [] : null),
                    onSelect: (item) => finish(item),
                });
            },
        });

        app.use(createAppI18n(locale));
        app.mount(host);
    });
}
