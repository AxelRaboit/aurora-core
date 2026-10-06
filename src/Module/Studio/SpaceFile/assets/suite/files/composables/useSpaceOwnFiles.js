import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/suite/useRequest.js";
import { openDocumentPicker } from "@/shared/utils/documentPicker.js";

/**
 * The files of the space itself, and the three writes on them.
 *
 * **Drop, pick, remove**, exactly as on a record: it is the same action, on a
 * different attachment. The picker is the media library's, so the list
 * browsed here is the one known from the documents screen rather than a
 * second one built for this page.
 *
 * Each write answers with the whole list: it is short, and a page that patched
 * its own copy would drift from the server in three actions.
 *
 * @param {Array} initial
 * @param {object} paths  uploadPath, attachPath, removePath, visibilityPath
 * @param {(data: object) => void} offerOrphaned
 */
export function useSpaceOwnFiles(initial, paths, offerOrphaned) {
    const { t } = useI18n();
    const { request } = useRequest();

    const files = ref(initial ?? []);
    const loading = ref(false);

    function apply(data) {
        if (Array.isArray(data?.spaceFiles)) files.value = data.spaceFiles;
    }

    /**
     * Drops a file on the space.
     *
     * `rawBody` rather than JSON: the bytes go up as multipart, and the
     * request helper lets the browser set its own boundary.
     */
    async function upload(file) {
        if (!file || loading.value) return;

        const form = new FormData();
        form.append("file", file);

        loading.value = true;
        try {
            const data = await request(paths.uploadPath, null, {
                rawBody: form,
            });

            if (!data?.success) return;

            apply(data);
            toast.success(t("suite.studio.space_files.added"));
        } finally {
            loading.value = false;
        }
    }

    /** Attaches a document already in the media library. */
    async function pick() {
        if (loading.value) return;

        const document = await openDocumentPicker();

        if (!document?.id) return;

        loading.value = true;
        try {
            const data = await request(paths.attachPath, {
                documentId: document.id,
            });

            if (!data?.success) {
                if (data?.errors) toast.error(Object.values(data.errors)[0]);

                return;
            }

            apply(data);
            toast.success(t("suite.studio.space_files.added"));
        } finally {
            loading.value = false;
        }
    }

    /** Removes the file from the space. The document stays in the media library. */
    async function remove(file) {
        if (!file || loading.value) return;

        loading.value = true;
        try {
            const data = await request(
                buildPath(paths.removePath, { id: file.id }),
            );

            if (!data?.success) return;

            apply(data);
            toast.success(t("suite.studio.space_files.removed"));

            // What nothing uses any more, offered rather than thrown away: the
            // same contract as a record's attachments and a note's images, so
            // the same composable reads it.
            offerOrphaned(data);
        } finally {
            loading.value = false;
        }
    }

    /**
     * Shows the file to the client, or hides it from them.
     *
     * The server answers with the whole list, as for the other writes; the
     * message says what just happened, because showing by accident and
     * showing are the same click.
     */
    async function toggleVisibility(file) {
        if (!file || loading.value || !paths.visibilityPath) return;

        const visible = !file.visibleToClient;

        loading.value = true;
        try {
            const data = await request(
                buildPath(paths.visibilityPath, { id: file.id }),
                { visible },
            );

            if (!data?.success) {
                if (data?.errors) toast.error(Object.values(data.errors)[0]);

                return;
            }

            apply(data);
            toast.success(
                t(
                    visible
                        ? "suite.studio.space_files.now_visible"
                        : "suite.studio.space_files.now_hidden",
                ),
            );
        } finally {
            loading.value = false;
        }
    }

    return { files, loading, upload, pick, remove, toggleVisibility };
}
