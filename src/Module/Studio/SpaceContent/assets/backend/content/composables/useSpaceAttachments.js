import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";

/**
 * The files of a space, and the writes on them.
 *
 * Shared by the three views for the same reason the thread is: they open the
 * same card and show the same files, and two copies would disagree the first
 * time one of them changed.
 *
 * `attachments` arrives keyed by card id, which is what the server sends - one
 * query for the whole space rather than a fetch on every card opened, because
 * a card shows its thumbnails without being opened at all.
 *
 * @param {import('vue').Ref} attachments    keyed by item id
 * @param {object} paths                     attachmentUploadPath, attachmentAttachPath, attachmentDetachPath
 * @param {(data: object) => void} applyBoard
 */
export function useSpaceAttachments(attachments, paths, applyBoard) {
    const { t } = useI18n();
    const { request } = useRequest();

    const attachmentLoading = ref(false);

    /** The files on one card, in reading order, or none. */
    const filesOf = computed(
        () => (item) => (item ? (attachments.value[item.id] ?? []) : []),
    );

    /**
     * Uploads one file and puts it on the card.
     *
     * `rawBody` rather than JSON: the bytes go up as multipart, and the request
     * helper leaves the browser to set its own boundary.
     */
    async function upload(item, file) {
        if (!item || attachmentLoading.value) return;

        const form = new FormData();
        form.append("file", file);

        attachmentLoading.value = true;
        try {
            const data = await request(
                buildPath(paths.attachmentUploadPath, { id: item.id }),
                null,
                { rawBody: form },
            );

            if (!data?.success) return;

            applyBoard(data);
            toast.success(t("backend.studio.space_content.attachment_added"));
        } finally {
            attachmentLoading.value = false;
        }
    }

    /**
     * Puts a document that is already in GED on the card.
     *
     * The picker is GED's own, reached through the shared wrapper, so the list
     * a studio member browses here is the list they know from the document
     * screen rather than a second one built for this page.
     */
    async function pick(item) {
        if (!item || attachmentLoading.value) return;

        const document = await openDocumentPicker();

        if (!document?.id) return;

        attachmentLoading.value = true;
        try {
            const data = await request(
                buildPath(paths.attachmentAttachPath, { id: item.id }),
                { documentId: document.id },
            );

            if (!data?.success) return;

            applyBoard(data);
            toast.success(t("backend.studio.space_content.attachment_added"));
        } finally {
            attachmentLoading.value = false;
        }
    }

    /** Takes a file off the card. The document stays in GED. */
    async function remove(attachment) {
        if (!attachment || attachmentLoading.value) return;

        attachmentLoading.value = true;
        try {
            const data = await request(
                buildPath(paths.attachmentDetachPath, { id: attachment.id }),
            );

            if (!data?.success) return;

            applyBoard(data);
            toast.success(t("backend.studio.space_content.attachment_removed"));
        } finally {
            attachmentLoading.value = false;
        }
    }

    return { attachmentLoading, filesOf, upload, pick, remove };
}
