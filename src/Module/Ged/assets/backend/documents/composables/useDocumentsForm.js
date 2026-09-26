import { ref } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { buildPath } from "@/shared/utils/http/buildPath.js";
import { useRequest } from "@/shared/composables/http/backend/useRequest.js";
import { useFormAction } from "@/shared/composables/form/useFormAction.js";
import { useDelete } from "@/shared/composables/form/useDelete.js";
import { required } from "@/shared/utils/validation/validators.js";
import { captureVideoPoster } from "@/shared/utils/media/captureVideoPoster.js";

export const DOCUMENT_STATUS_BADGE = {
    draft: "gray",
    published: "emerald",
    archived: "accent",
};

function emptyForm() {
    return {
        title: "",
        description: "",
        status: "draft",
        categoryId: null,
        tagIds: [],
        folderId: null,
        // File metadata returned by the /upload endpoint after a local
        // upload. Carried along on the form submit. No more Media coupling.
        filePath: null,
        fileName: null,
        originalName: null,
        mimeType: null,
        size: null,
        width: null,
        height: null,
        // The still shown for a file the browser cannot draw by itself: a
        // PDF's first page, a film's poster frame. Carried on submit like
        // filePath, or the document is saved without the one it just got.
        thumbnailPath: null,
        // Image-only metadata (alt for a11y/SEO, caption shown alongside).
        alt: "",
        caption: "",
        // Where the picture is cropped around. Set elsewhere (the focal
        // picker), but the update replaces it with whatever it is sent, so
        // the form carries it back or every save recentres the picture.
        focalX: null,
        focalY: null,
        // Unused on purpose: the "À conserver" badge.
        kept: false,
        // The document this one is declined from, and what sets it apart.
        // `originalTitle` is for the screen only; the server reads the id.
        originalId: null,
        originalTitle: null,
        alternateLabel: "",
    };
}

export function useDocumentsForm(
    createPath,
    updatePath,
    deletePath,
    reset,
    uploadPath = "",
) {
    const { t } = useI18n();
    const { request } = useRequest();

    const statusOptions = [
        { value: "draft", label: t("backend.ged.documents.status_draft") },
        {
            value: "published",
            label: t("backend.ged.documents.status_published"),
        },
        {
            value: "archived",
            label: t("backend.ged.documents.status_archived"),
        },
    ];

    const showCreate = ref(false);
    const newDoc = ref(emptyForm());
    const uploadingCreate = ref(false);

    const {
        errors: createErrors,
        loading: createLoading,
        submit: submitCreate,
        clearErrors: clearCreate,
    } = useFormAction({
        rules: () => ({
            title: () =>
                required(t("backend.ged.documents.errors.title_required"))(
                    newDoc.value.title,
                ),
        }),
        url: () => createPath,
        body: () => ({ ...newDoc.value }),
        onSuccess: () => {
            showCreate.value = false;
            toast.success(t("backend.ged.documents.created"));
            reset();
        },
    });

    function openCreate() {
        newDoc.value = emptyForm();
        clearCreate();
        showCreate.value = true;
    }

    /**
     * Body for the upload call: the file, plus the poster frame when the file
     * is a film.
     *
     * A `<video preload="none">` without a poster is a black rectangle at the
     * browser's default ratio, and the browser that is about to upload the
     * film is the one place a frame can be drawn without asking the server to
     * install a decoder. `captureVideoPoster` returns null for anything that
     * is not a playable video, and for a video it could not draw, so the
     * upload itself is never held up by it.
     */
    async function uploadBody(file) {
        const rawBody = new FormData();
        rawBody.append("file", file);

        const capture = await captureVideoPoster(file);
        if (capture) {
            rawBody.append("poster", capture.poster);
            rawBody.append("videoWidth", String(capture.width));
            rawBody.append("videoHeight", String(capture.height));
        }

        return rawBody;
    }

    /**
     * Uploads a local file to /backend/ged/documents/upload, then hydrates
     * the form with the returned metadata. Two-step pattern: the actual
     * Document row is created later on form submit.
     */
    async function onLocalFileCreate(file) {
        if (!uploadPath || !file) return;
        uploadingCreate.value = true;
        const data = await request(uploadPath, null, {
            rawBody: await uploadBody(file),
        });
        uploadingCreate.value = false;
        if (!data) return;
        if (data.success && data.filePath) {
            newDoc.value.filePath = data.filePath;
            newDoc.value.fileName = data.fileName;
            newDoc.value.originalName = data.originalName;
            newDoc.value.mimeType = data.mimeType;
            newDoc.value.size = data.size;
            newDoc.value.width = data.width;
            newDoc.value.height = data.height;
            newDoc.value.thumbnailPath = data.thumbnailPath ?? null;
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    const showEdit = ref(false);
    const editingDoc = ref(null);
    const editForm = ref(emptyForm());
    const uploadingEdit = ref(false);

    const {
        errors: editErrors,
        loading: editLoading,
        submit: submitEdit,
        clearErrors: clearEdit,
    } = useFormAction({
        rules: () => ({
            title: () =>
                required(t("backend.ged.documents.errors.title_required"))(
                    editForm.value.title,
                ),
        }),
        url: () => buildPath(updatePath, { id: editingDoc.value.id }),
        body: () => ({ ...editForm.value }),
        onSuccess: () => {
            showEdit.value = false;
            toast.success(t("backend.ged.documents.updated"));
            reset();
        },
    });

    function openEdit(doc) {
        editingDoc.value = doc;
        editForm.value = {
            title: doc.title,
            description: doc.description ?? "",
            status: doc.status,
            categoryId: doc.categoryId ?? null,
            tagIds: doc.tagIds ?? [],
            folderId: doc.folderId ?? null,
            // On edit, file metadata is round-tripped but only sent back to
            // the server if the user uploads a new one (the manager checks
            // for filePath !== current to decide whether to record a new
            // version). So passing the existing values keeps the form in
            // sync visually without forcing a re-snapshot.
            filePath: doc.filePath ?? null,
            fileName: doc.fileName ?? null,
            originalName: doc.originalName ?? null,
            mimeType: doc.fileMime ?? null,
            size: doc.fileSize ?? null,
            width: doc.width ?? null,
            height: doc.height ?? null,
            thumbnailPath: doc.thumbnailPath ?? null,
            alt: doc.alt ?? "",
            caption: doc.caption ?? "",
            focalX: doc.focalX ?? null,
            focalY: doc.focalY ?? null,
            kept: doc.kept ?? false,
            originalId: doc.originalId ?? null,
            originalTitle: doc.originalTitle ?? null,
            alternateLabel: doc.alternateLabel ?? "",
        };
        clearEdit();
        showEdit.value = true;
    }

    async function onLocalFileEdit(file) {
        if (!uploadPath || !file) return;
        uploadingEdit.value = true;
        const data = await request(uploadPath, null, {
            rawBody: await uploadBody(file),
        });
        uploadingEdit.value = false;
        if (!data) return;
        if (data.success && data.filePath) {
            editForm.value.filePath = data.filePath;
            editForm.value.fileName = data.fileName;
            editForm.value.originalName = data.originalName;
            editForm.value.mimeType = data.mimeType;
            editForm.value.size = data.size;
            editForm.value.width = data.width;
            editForm.value.height = data.height;
            editForm.value.thumbnailPath = data.thumbnailPath ?? null;
        } else {
            toast.error(t("shared.common.error"));
        }
    }

    const {
        pendingDelete,
        loading: deleteLoading,
        confirm: confirmDelete,
        submit: doDelete,
    } = useDelete(deletePath, () => reset(), "backend.ged.documents.deleted");

    return {
        statusOptions,
        showCreate,
        newDoc,
        uploadingCreate,
        createErrors,
        createLoading,
        openCreate,
        onLocalFileCreate,
        submitCreate,
        showEdit,
        editingDoc,
        editForm,
        uploadingEdit,
        editErrors,
        editLoading,
        openEdit,
        onLocalFileEdit,
        submitEdit,
        pendingDelete,
        deleteLoading,
        confirmDelete,
        doDelete,
    };
}
